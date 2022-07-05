<?php

namespace Tarasovich\YamlFileMergeLoader;

use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader as BaseYamlFileLoader;

class YamlFileMergeLoader extends BaseYamlFileLoader
{
    private ?string $baseDirectory = null;
    private ?\ReflectionMethod $reflection = null;

    public static function replaceOriginalLoader(ContainerBuilder $container, LoaderInterface $containerLoader, ?string $environment): LoaderInterface
    {
        if ($containerLoader instanceof BaseYamlFileLoader) {
            return new YamlFileMergeLoader($container, $containerLoader->getLocator(), $environment);
        }

        if ($containerLoader instanceof DelegatingLoader) {
            /** @var LoaderResolver $resolver */
            $resolver = $containerLoader->getResolver();
            $loaders = [];
            foreach ($resolver->getLoaders() as $loader) {
                if ($loader instanceof BaseYamlFileLoader) {
                    $loader = new YamlFileMergeLoader($container, $loader->getLocator(), $environment);
                }

                $loaders[] = $loader;
            }

            $resolver = new LoaderResolver($loaders);
            $containerLoader = new DelegatingLoader($resolver);
        }

        return $containerLoader;
    }

    /**
     * {@inheritdoc}
     */
    public function load($resource, string $type = null, ?array $merging = null): ?array
    {
        $path = $this->locator->locate($resource);

        $content = $this->loadFile($path);

        $this->container->fileExists($path);

        // empty file
        if (null === $content) {
            return $merging;
        }

        $currentDir = strtr(pathinfo($path, PATHINFO_DIRNAME), ['\\' => '/']);
        $this->setCurrentDir($currentDir);
        if (!$merging) {
            $this->baseDirectory = $currentDir;
        }

        $content = $this->processContent($content, $path, $merging);
        if ($merging) {
            return $content;
        }

        $this->getLoadContentReflection()->invoke($this, $content, $path);

        if ($this->env && $merging === null && isset($content['when@' . $this->env])) {
            $env = $this->env;
            $this->env = null;

            try {
                $this->getLoadContentReflection()->invoke($this, $content['when@' . $env], $path);
            } finally {
                unset($content['when@' . $env]);
                $this->env = $env;
            }
        }

        return null;
    }

    private function processContent(array $content, string $file, ?array $merging = null): array
    {
        $fileDirectory = strtr(dirname($file), ['\\' => '/']);

        if (array_key_exists('imports', $content)) {
            if (!\is_array($content['imports'])) {
                throw new InvalidArgumentException(sprintf('The "imports" key should contain an array in "%s". Check your YAML syntax.', $file));
            }

            foreach ($content['imports'] as $index => $import) {
                if (!\is_array($import)) {
                    $content['imports'][$index] = $import = ['resource' => $import];
                }

                if (!isset($import['resource'])) {
                    throw new InvalidArgumentException(sprintf('An import should provide a resource in "%s". Check your YAML syntax.', $file));
                }

                if (!isset($import['merge']) || $import['merge'] !== true) {
                    if ($this->baseDirectory !== $fileDirectory) {
                        $prefix = ltrim(strtr($fileDirectory, [$this->baseDirectory => '']), '/') . '/';
                        $content['imports'][$index]['resource'] = $prefix . $import['resource'];
                    }

                    continue;
                }

                $ignoreNotFound = false;
                $ignoreErrors = $import['ignore_errors'] ?? false;
                if (is_string($ignoreErrors)) {
                    $ignoreNotFound = $ignoreErrors === 'not_found';
                    $ignoreErrors = false;
                }
                unset($content['imports'][$index]);

                try {
                    foreach ($this->glob($import['resource'], true, $_) as $importFile) {
                        /** @var \SplFileInfo $importFile */
                        $content = $this->load($importFile->getRealPath(), null, $content);
                    }
                } catch (LoaderLoadException $e) {
                    if ($ignoreErrors) {
                        continue;
                    }

                    if (!$ignoreNotFound || !$e->getPrevious() instanceof FileLocatorFileNotFoundException) {
                        throw $e;
                    }
                }
            }
        }

        if ($this->env && $merging !== null && isset($content['when@' . $this->env])) {
            $env = $this->env;
            $this->env = null;

            try {
                $content = $this->processContent($content, $file, $content['when@' . $env]);
            } finally {
                unset($content['when@' . $env]);
                $this->env = $env;
            }
        }

        if ($merging !== null) {
            $content = $this->merge($merging, $content);
        }

        return $content;
    }

    private function merge(array $content, array $append): array
    {
        foreach ($append as $namespace => $data) {
            if (empty($data)) {
                continue;
            }

            if (!array_key_exists($namespace, $content)) {
                $content[$namespace] = $data;

                continue;
            }

            if ($namespace !== 'services') {
                if (!str_starts_with($namespace, 'when@')) {
                    $content[$namespace] = array_merge($data, $content[$namespace]);
                }

                continue;
            }

            $defaults = (array_key_exists('_defaults', $data)) ? $data['_defaults'] : [];
            unset($data['services']['_defaults']);

            foreach ($data as $service => $definition) {
                if ($definition !== null && !is_string($definition) && $service !== '_instanceof') {
                    foreach ($defaults as $option => $default) {
                        if (!array_key_exists($option, $definition)) {
                            $definition[$option] = $default;
                        }
                    }
                }

                if (!array_key_exists('services', $content)) {
                    $content['services'] = [];
                }

                if (!array_key_exists($service, $content['services'])) {
                    $content['services'][$service] = $definition;

                    continue;
                }

                if (is_string($content['services'][$service]) || is_string($definition) || $definition === null) {
                    continue;
                }

                $content['services'][$service] = array_merge($definition, $content['services'][$service]);
            }
        }

        return $content;
    }

    private function getLoadContentReflection(): \ReflectionMethod
    {
        if ($this->reflection === null) {
            $this->reflection = (new \ReflectionClass($this))->getMethod('loadContent');
            $this->reflection->setAccessible(true);
        }

        return $this->reflection;
    }

}