<?php

namespace Playbloom\Satisfy\Model;

/**
 * Processor for uploaded composer.lock files.
 *
 * @author Julius Beckmann <php@h4cc.de>
 */
class LockProcessor
{

    private $manager;

    /**
     * Constructor
     *
     * @param Manager $manager
     */
    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Adds repositories from composer.lock JSON file.
     *
     * @param \SplFileObject $file
     */
    public function processFile(\SplFileObject $file)
    {
        $data = $this->getComposerLockData($file);
        $this->addReposFromData($data);
    }

    /**
     * Reads and decodes json from given file.
     *
     * @param \SplFileObject $file
     * @return mixed
     */
    private function getComposerLockData(\SplFileObject $file)
    {
        $json = file_get_contents($file->getRealPath());
        return json_decode($json);
    }

    /**
     * Adds all repos from composer.lock data.
     *
     * @param $data
     * @return void
     */
    private function addReposFromData($data)
    {
        // Had to use the isset, because current version of json-schema
        // cant handle "require" constraints,
        $repos = array();
        if (isset($data->packages)) {
            foreach ($data->packages as $package) {
                if (isset($package->source)) {
                    $source = $package->source;
                    if (isset($source->url) && isset($source->type)) {
                        $repo = new Repository();
                        $repo->setUrl($source->url);
                        $repo->setType($source->type);
                        $repos[] = $repo;
                    }
                }
            }
        }

        $this->manager->addAll($repos);
    }

}
