zip-plugin:
    rm -Rf cshp-plugin-tracker cshp-plugin-tracker.zip; mkdir cshp-plugin-tracker && rsync -av --progress * cshp-plugin-tracker --exclude cshp-plugin-tracker --exclude .git --exclude .gitattributes --exclude .gitignore --exclude justfile --exclude bitbucket-pipelines.yml --exclude .idea --exclude bitbucket-pipelines.yml --exclude composer.json --exclude composer.lock --exclude inc/beta.php --exclude inc/beta-wpcli.php && zip -rv cshp-plugin-tracker.zip cshp-plugin-tracker && rm -Rf cshp-plugin-tracker

clean-require-dev:
    bash gitignore-composer-require-dev.sh