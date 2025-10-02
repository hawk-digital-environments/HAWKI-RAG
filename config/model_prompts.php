<?php

use Symfony\Component\Yaml\Yaml;

return [
    'prompts' => Yaml::parseFile(resource_path('model_prompts.yaml')),
];