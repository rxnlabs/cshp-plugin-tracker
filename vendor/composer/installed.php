<?php return array(
    'root' => array(
        'name' => 'cornershop/cshp-plugin-tracker',
        'pretty_version' => 'dev-main',
        'version' => 'dev-main',
        'reference' => 'ca4e835c705360353099dc98fcbb3c17bceb1cfc',
        'type' => 'library',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'cornershop/cshp-plugin-tracker' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'ca4e835c705360353099dc98fcbb3c17bceb1cfc',
            'type' => 'library',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'league/container' => array(
            'pretty_version' => '5.x-dev',
            'version' => '5.9999999.9999999.9999999-dev',
            'reference' => 'd4863766993ff24d2d245984c7861e35fc1a9384',
            'type' => 'library',
            'install_path' => __DIR__ . '/../league/container',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'orno/di' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '~2.0',
            ),
        ),
        'psr/container' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '707984727bd5b2b670e59559d3ed2500240cf875',
            'type' => 'library',
            'install_path' => __DIR__ . '/../psr/container',
            'aliases' => array(
                0 => '2.0.x-dev',
            ),
            'dev_requirement' => false,
        ),
        'psr/container-implementation' => array(
            'dev_requirement' => false,
            'provided' => array(
                0 => '^1.0',
            ),
        ),
        'yahnis-elsts/plugin-update-checker' => array(
            'pretty_version' => 'v5.5',
            'version' => '5.5.0.0',
            'reference' => '845d65da93bcff31649ede00d9d73b1beadbb7f0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../yahnis-elsts/plugin-update-checker',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
