## Example

Example of `behat.yml` configuration
```yaml
default:
    suites:
        default:
            contexts:
                - MTZ\BehatContext\Doctrine\DoctrineContext
```

Also put next to the `config/services_test.yaml` file:
```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    MTZ\BehatContext\Doctrine\DoctrineContext:
```

Example of scenario:
```Gherkin
  Scenario: My awesome Scenario
    And Created entities of "App\Entity\Post" with
      | id    | title       | description      |
      | 11100 | Some title  | some description |
    Then Instance of "App\Entity\Post" with "id" equal to "11100" contains the following data:
      | id    | title       | description      |
      | 11100 | Some title  | some description |
```
