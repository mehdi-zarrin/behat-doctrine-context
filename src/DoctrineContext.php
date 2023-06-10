<?php

namespace MTZ\BehatContext\Doctrine;

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Mink;
use Behat\MinkExtension\Context\MinkAwareContext;
use DateTime;
use Doctrine\Common\DataFixtures\ReferenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Instantiator\Exception\ExceptionInterface;
use Doctrine\Instantiator\Instantiator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\ORMException;
use ReflectionException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class DoctrineContext implements MinkAwareContext
{
    const PLACEHOLDER_NULL = 'null';

    /**
     * @var Mink|null
     */
    private $mink;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var PropertyAccessor
     */
    private $propertyAccessor;

    /**
     * @var ReferenceRepository|null
     */
    private $referenceRepository;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        EntityManagerInterface $entityManager
    )
    {
        $this->entityManager = $entityManager;
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    public function setMink(Mink $mink)
    {
        $this->mink = $mink;
    }

    public function setMinkParameters(array $parameters)
    {
        // We don't need mink parameters right now
    }


    /**
     * @Given /^Created entities of "(?P<className>[^"]*)" with$/
     * @param string $className
     * @param TableNode $table
     *
     * @throws MappingException
     * @throws ReflectionException
     * @throws ORMException
     */
    public function iCreateSomeEntitiesWith(string $className, TableNode $table)
    {
        foreach ($table->getColumnsHash() as $row) {
            $this->createEntity($className, $row);
        }
    }

    /**
     * @param string $className
     * @param array $data
     * @throws ExceptionInterface
     */
    private function createEntity(string $className, array $data)
    {
        $entity = (new Instantiator())->instantiate($className);

        $metadata = $this->entityManager->getClassMetadata(get_class($entity));
        $idGenerator = $metadata->idGenerator;
        $generatorType = $metadata->generatorType;

        foreach ($data as $field => &$value) {
            if ($metadata->usesIdGenerator() && $metadata->isIdentifier($field)) {
                $metadata->setIdGenerator(new AssignedGenerator());
                $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
            }

            $this->propertyAccessor->setValue(
                $entity,
                $field,
                $this->getFieldValue($metadata, $field, $value)
            );
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $metadata->setIdGenerator($idGenerator);
        $metadata->setIdGeneratorType($generatorType);
    }

    private function getFieldValue(ClassMetadata $metadata, string $field, $value)
    {
        if (self::PLACEHOLDER_NULL === $value) {
            return null;
        }

        switch ($metadata->getTypeOfField($field)) {
            case Types::TIME_MUTABLE:
            case Types::TIME_IMMUTABLE:
            case Types::DATETIME_MUTABLE:
            case Types::DATETIME_IMMUTABLE:
            case Types::DATETIMETZ_MUTABLE:
            case Types::DATETIMETZ_IMMUTABLE:
                return new DateTime($value);
            case Types::BOOLEAN:
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case Types::SIMPLE_ARRAY:
                return explode(',', $value);
            case Types::BIGINT:
            case Types::INTEGER:
            case Types::SMALLINT:
                return (int)$value;
            case Types::FLOAT:
                return (float)$value;
            case Types::JSON:
                return json_decode($value, true);
            default:
                if (in_array($field, $metadata->getAssociationNames(), true)
                    && $metadata->isAssociationWithSingleJoinColumn($field)
                ) {
                    return $this
                        ->entityManager
                        ->getReference(
                            $metadata->getAssociationTargetClass($field),
                            $value
                        );
                }
                break;
        }

        return $value;
    }

    /**
     * @Then Instance of :class with :field equal to :value contains the following data:
     *
     * @param string $class
     * @param string $field
     * @param              $value
     * @param TableNode $table
     *
     * @throws \Exception
     */
    public function checkRecord(string $class, string $field, $value, TableNode $table)
    {
        $repo = $this->entityManager->getRepository($class);
        $value = $this->getReference($value) ?? $value;
        $entity = $repo->findOneBy([$field => $value]);
        if (!$entity) {
            throw new \RuntimeException('Unable to find the entity');
        }
        $this->entityManager->refresh($entity);

        $data = $table->getColumnsHash()[0];
        foreach ($data as $field => &$fieldValue) {
            $fieldValue = $this->getReference($fieldValue) ?? $fieldValue;
            $fieldValue = $this->valueToString($fieldValue);
            $entityValue = $this->propertyAccessor->getValue($entity, $field);
            $entityValue = $this->valueToString($entityValue);

            if ($entityValue !== $fieldValue) {
                throw new \Exception(sprintf(
                    'Value is equal to %s but %s was expected',
                    $entityValue,
                    $fieldValue
                ));
            }
        }
    }

    /**
     * @Then Find instance of :class with :field equal to :value and save to :reference
     *
     * @param string $class
     * @param string $field
     * @param        $value
     * @param string $reference
     */
    public function addReference(string $class, string $field, $value, string $reference)
    {
        $repo = $this->entityManager->getRepository($class);
        $entity = $repo->findOneBy([$field => $value]);
        if (!$entity) {
            throw new \RuntimeException('Unable to find the entity');
        }
        $this->getReferenceRepository()->addReference($reference, $entity);
    }

    /**
     * @Then Instance of :class with :field equal to :value not found
     *
     * @param string $class
     * @param string $field
     * @param              $value
     *
     * @throws \Exception
     */
    public function notFound(string $class, string $field, $value)
    {
        $repo = $this->entityManager->getRepository($class);
        $entity = $repo->findOneBy([$field => $value]);

        if ($entity) {
            throw new \RuntimeException(sprintf('Entity %s with %s = %s is exist', $class, $field, (string)$value));
        }
    }

    private function valueToString($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (true === $value) {
            return 'true';
        }

        if (false === $value) {
            return 'false';
        }

        if (null === $value) {
            return 'null';
        }

        if (is_object($value)) {
            $metadata = $this->entityManager->getClassMetadata(get_class($value));
            $identifier = $metadata->getSingleIdentifierFieldName();
            $value = $this->propertyAccessor->getValue($value, $identifier);
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string)$value;
    }

    /**
     * Find a saved object by reference that starts with @
     *
     * @param string $reference
     * @return object|null
     */
    private function getReference(string $reference)
    {
        if (strpos($reference, '@') !== 0) {
            return null;
        }

        $reference = ltrim($reference, '@');

        $referenceRepository = $this->getReferenceRepository();

        if (!$referenceRepository->hasReference($reference)) {
            return null;
        }

        return $referenceRepository->getReference($reference);
    }

    private function getReferenceRepository(): ReferenceRepository
    {
        if (!$this->referenceRepository) {
            $this->referenceRepository = new ReferenceRepository($this->entityManager);
        }

        return $this->referenceRepository;
    }
}