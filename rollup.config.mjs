// rollup config.js
import {rollup} from "rollup";
// when importing node_modules package into custom code created by us, RollupJS will look for that import in the node_modules folder if a full file path is not given when the module is imported
import resolve from '@rollup/plugin-node-resolve';
// convert CommonJS modules to es6, so they can be added to the bundle
import commonjs from '@rollup/plugin-commonjs';
// used to convert .jsx files and code to js and replaces the use of babel to transpile es6 code to older browsers (used when building custom blocks or extending the block editor using React components)
import swc from '@rollup/plugin-swc';
// used to for file minification
import terser from '@rollup/plugin-terser';
// resolve wordpress external dependencies, similar to the dependy-extraction-webpack-plugin https://github.com/WordPress/gutenberg/tree/trunk/packages/dependency-extraction-webpack-plugin
import wpResolve from 'rollup-plugin-wp-resolve';
// used to import multiple entry files to this custom Rollup config using a glob pattern
import fg from 'fast-glob';
import path from 'node:path';
import typescript from '@rollup/plugin-typescript';

/**
 * Transform the entry file so that the output file is named the same file name as the entry file but remove the original root folder from the final file output directory.
 */
const createEntryOutput = (entryFiles, removeRelativePath, outputDir, createMinificationFiles) => {
    return entryFiles.map((relativeFilePath) => {
        let outputFile = relativeFilePath;
        
        if (typeof removeRelativePath === 'string' && outputFile.startsWith(removeRelativePath)) {
            outputFile = outputFile.split(removeRelativePath)[1];
        }

        outputFile = ltrim(outputFile, '/');

        if (typeof outputDir === 'string' && outputDir.length) {
            outputFile = `${ltrim(outputDir, '/')}/${outputFile}`;
        }

        const fileExtension = path.extname(relativeFilePath);

        // Change the file extension to .js if it is .ts, .tsx, or .jsx
        if (['.ts', '.tsx', '.jsx'].includes(fileExtension)) {
            outputFile = `${outputFile.slice(0, -fileExtension.length)}.js`;
        }

        const bundleFileWithoutExtension = outputFile.slice(0, outputFile.length - fileExtension.length);
        const iifeVarName = bundleFileWithoutExtension.replace(/[\/\.]/g, '_').toUpperCase();
        
        let minifiedOutputFile = '';
        if (createMinificationFiles && bundleFileWithoutExtension.length) {
            minifiedOutputFile = `${bundleFileWithoutExtension}.min.js`;
        }


        return {
            entry: relativeFilePath, 
            output: outputFile,
            min_output: minifiedOutputFile,
            name: iifeVarName
        };
    });
};

/**
 * Custom, lightweight JS version of PHP's ltrim function
 */ 
const ltrim = (str, charToRemove) => {
    while (str.startsWith(charToRemove)) {
        str = str.slice(charToRemove.length);
    }
    return str;
}

// specify these imports/requires as global modules so RollupJS will not try to compile their code into the bundle.
// specify the name of the global variable that the import will be transformed into when the file is bundled, which will also refer to the name of the variable in the global scope
const globalsTransform = {
    jquery: '$',
    react: 'window.React',
    'react-dom': 'window.ReactDOM',
    wp: 'window.wp',
};

// increase the time in milliseconds for a build delay to give the filesystem a chance for the file content to be fully written to disk. 
// Decreases chances RollupJS will output an empty file
// see https://rollupjs.org/configuration-options/#watch-builddelay
const buildDelay = 500;

const entryFiles = fg.sync(['_assets_src/js/**/*.js', '_assets_src/js/**/*.ts', '_assets_src/js/**/*.jsx', '_assets_src/js/**/*.tsx', '!_assets_src/js/partials/**/*.js', '!_assets_src/js/vendor/**/*.js']);
console.log(entryFiles);
// create minification version of the entries
let createMinificationFiles = true;
const entryOutputFiles = createEntryOutput(entryFiles, '_assets_src/js/', 'build/js', createMinificationFiles);
// add multiple files to the rollup config so multiple bundles are built as individual files
let rollupConfig = entryOutputFiles.map((entryOutputFile) => {
    let outputConfig = [
        {
            file: entryOutputFile.output,
            format: 'iife',
            name: entryOutputFile.name,
            globals: globalsTransform,
            sourcemap: true,
        }
    ];

    if (entryOutputFile.min_output.length) {
        let minifyConfig = { ...outputConfig[0] };
        minifyConfig.file = entryOutputFile.min_output;

        minifyConfig.plugins = minifyConfig.plugins || [];
        minifyConfig.plugins.push(terser());
        outputConfig.push(minifyConfig);
    }

    let configObject = {
        input: entryOutputFile.entry,
        output: outputConfig,
        external: [Object.keys(globalsTransform)],
        plugins: [
            wpResolve(),
            resolve({ extensions: ['.js', '.ts', '.jsx'] }),
            typescript({ tsconfig: './tsconfig.json' }),
            swc({ swc: {'jsc': { 'parser': {'syntax': 'ecmascript', 'jsx': true}}, 'minify': false }, exclude: ['node_modules/**'] }),
            commonjs()
        ],
        watch: {
            clearScreen: false,
            exclude: 'node_modules/**',
            include: '_src/js/**/*.js',
            buildDelay: buildDelay,
        }
    };

    return configObject;
});

// add any additional configs to the rollup export
const additionalConfigs = [
    /*{
        input: '',
        output: {},
        plugins: [],
        watch: {
            clearScreen: false,
            exclude: 'node_modules/**'
        },
    }*/
];

rollupConfig = rollupConfig.concat(additionalConfigs).flat();
export default rollupConfig;