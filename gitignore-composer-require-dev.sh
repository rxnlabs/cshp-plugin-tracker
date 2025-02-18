#!/bin/bash

# Ensure the script stops if an error occurs
set -e

# Constants
COMPOSER_FILE="composer.json"
GITIGNORE_FILE=".gitignore"

# Check that composer.json exists
if [[ ! -f $COMPOSER_FILE ]]; then
  echo "Error: $COMPOSER_FILE does not exist in the current directory."
  exit 1
fi

# Ensure .gitignore file exists (create if necessary)
if [[ ! -f $GITIGNORE_FILE ]]; then
  touch $GITIGNORE_FILE
fi

# Function to append a package path to the .gitignore file
add_to_gitignore() {
  local package_path="$1"

  # Check if the entry already exists in .gitignore
  if ! grep -qxF "$package_path" "$GITIGNORE_FILE"; then
    # Add a new line before appending if the file doesn't already end with one
    if [[ $(tail -c1 "$GITIGNORE_FILE" | wc -l) -eq 0 ]]; then
      echo "" >> "$GITIGNORE_FILE"
    fi
    echo "$package_path" >> "$GITIGNORE_FILE"
    echo "Added $package_path to $GITIGNORE_FILE."
  else
    echo "$package_path is already in $GITIGNORE_FILE, skipping."
  fi
}

# Function to process a composer.json file and append vendor entries for 'require-dev' packages
process_require_dev_packages() {
  local composer_json_file="$1"

  # Extract 'require-dev' package names using jq
  local require_dev_packages=$(jq -r '.["require-dev"] | keys[]' < "$composer_json_file")

  # Loop through each require-dev package
  for package in $require_dev_packages; do
    # Convert package name to vendor directory path
    local package_path="vendor/$(echo "$package" | tr '/' '/')"

    # Add the package path to .gitignore
    add_to_gitignore "$package_path"

    # Check if the package has its own composer.json (nested dependencies)
    local nested_composer_json="${package_path}/composer.json"
    if [[ -f "$nested_composer_json" ]]; then
      # Recursively process the nested 'require-dev' packages
      process_require_dev_packages "$nested_composer_json"
    fi
  done
}

# Start processing the top-level composer.json
echo "Processing 'require-dev' dependencies in $COMPOSER_FILE..."
process_require_dev_packages "$COMPOSER_FILE"

echo "Update completed. All 'require-dev' vendor folders and their nested dependencies are now ignored."