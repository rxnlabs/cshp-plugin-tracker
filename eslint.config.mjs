export default [
    {
        // Setting environment modes
        env: {
            browser: true,
            es2021: true,
            node: true,
        },

        // Adding parser for TypeScript support
        parser: '@typescript-eslint/parser',

        // Parser options
        parserOptions: {
            ecmaVersion: 2021, // Support modern ECMAScript features
            sourceType: 'module', // Use ES modules
        },

        // Extending base rules
        extends: [
            'eslint:recommended',
            'plugin:@typescript-eslint/recommended',
        ],

        // Plugins used
        plugins: ['@typescript-eslint'],

        // Overrides for specific file types
        overrides: [
            {
                files: ['*.ts', '*.tsx'], // Target TypeScript files
                rules: {
                    '@typescript-eslint/no-unused-expressions': [
                        'error',
                        {
                            allowShortCircuit: true,
                            allowTernary: true,
                            allowTaggedTemplates: true,
                        },
                    ],
                    '@typescript-eslint/no-explicit-any': 'warn',
                    '@typescript-eslint/explicit-function-return-type': 'warn',
                },
            },
        ],

        // General rules
        rules: {
            // Turning off specific JavaScript rules to avoid conflicts with TypeScript plugin
            'no-unused-vars': 'off',
            'no-console': 'warn',
            eqeqeq: 'error',
            quotes: ['error', 'single'],
            semi: ['error', 'always'],
            indent: ['error', 2],
            curly: 'error',
            'no-else-return': 'error',
            'comma-dangle': ['error', 'only-multiline'],
            'object-curly-spacing': ['error', 'always'],
            'arrow-spacing': ['error', { before: true, after: true }],
            '@typescript-eslint/consistent-type-imports': [
                'error',
                { prefer: 'type-imports' },
            ],
        },

        // Patterns to ignore
        ignorePatterns: ['node_modules/', 'dist/'],

        // Custom global variables
        globals: {
            simpleDatatables: 'readonly',
            cshp_pt: 'readonly',
        },
    },
];