
Symfony YAML merge loader
=========================

This is extension of **Symfony\Component\DependencyInjection\Loader\YamlFileLoader** adding possibility to merge imports in **services.yaml**:
```yaml
# config/services.yaml
imports:
- { resource: 'services/*.yaml', merge: true }
```

Complication
------------

From [Official symfony docs](https://symfony.com/doc/current/service_container/import.html#importing-configuration-with-imports):
> When loading a configuration file, Symfony loads first the imported files and then it processes the parameters and services defined in the file.
> If you use the default services.yaml configuration, the App\ definition creates services for classes found in ../src/*.
> If your imported file defines services for those classes too, they will be overridden.
>
> A possible solution for this is to add the classes and/or directories of the imported files in the exclude option of the App\ definition.
> Another solution is to not use imports and add the service definitions in the same file, but after the App\ definition to override it.“

With this extension you `don't need to exclude` imported services from `App\` definition.

Installation
------------

### Get the extension using composer

Add YamlFileMergeLoader by running this command from the terminal at the root of your Symfony project:

```bash
composer require tarasovich/symfony-yaml-merge-loader
```

### Replace default loader
```php
// src/Kernel.php (your kernel class may be defined in a different class/path)
namespace App;

use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Tarasovich\YamlFileMergeLoader;
// ...

class Kernel extends BaseKernel
{
    protected function getContainerLoader(ContainerInterface $container): LoaderInterface
    {
        /** @var ContainerBuilder $container */
        return YamlFileMergeLoader::replaceOriginalLoader(
            $container,
            parent::getContainerLoader($container),
            $this->getEnvironment()
        );
    }
}
```

### Organize imports files

Move some services and/or parameters definitions into other file, eg:
```yaml
# config/services/hello_word.yaml
parameters:
  app.hello_word.text: 'Hello World!'

services:
  _defaults:
    autowire: true
    autoconfigure: true

  App\Service\HelloWordService:
    arguments:
      $defaultText: '%app.hello_word.text%'
```

Import resource to your services.yaml with `merge: true` option.

```yaml
# config/services.yaml
imports:
- { resource: 'services/*.yaml', merge: true }

services:
  _defaults:
    autowire: true
    autoconfigure: true

  App\:
    resource: '../src/'
    exclude:
      - '../src/DependencyInjection/'
      - '../src/Entity/'
      - '../src/Kernel.php'
      - '../src/Tests/'
```

### That was it!

Now your `App\Service\HelloWordService` will be not overridden with the `App\` definition from `config/services.yaml`.