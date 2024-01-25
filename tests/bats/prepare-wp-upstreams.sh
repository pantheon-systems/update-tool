#!/bin/bash

set -e

echo "Preparing WordPress upstreams..."

# Check Git config.
echo "Checking Git config..."
gh auth status
if [ "$(git config --get user.email)" != "bot@getpantheon.com" ]; then
	echo "Configuring Git..."
	git config --global user.email "bot@getpantheon.com"
	git config --global user.name "Pantheon Automation"
fi


echo "Getting configuration file..."
config="${GITHUB_WORKSPACE}/tests/fixtures/home/test-configuration.yml"
projects=("wp" "wpms")

mkdir -p "${GITHUB_WORKSPACE}/work/"
working_copy_path="${GITHUB_WORKSPACE}/work/"
for project in "${projects[@]}"; do
	echo "Preparing ${project}..."

	# Change directory to the project path.
	cd "${working_copy_path}" || { echo "Failed to change directory to ${working_copy_path}"; exit 1; }

	# Clone the project.
	repo_ssh_url=$(yq e ".projects.${project}.repo" "${config}")
	# Use an HTTPS URL for cloning.
	repo_url=$(echo "${repo_ssh_url}" | sed -e 's|git@github.com:\(.*\)\.git|https://github.com/\1.git|')
	echo "Cloning ${project} from ${repo_url}..."
	git clone "${repo_url}" ./"${project}"
	cd "${project}" || { echo "Failed to change directory to ${project}"; exit 1; }

	# Parse the JSON file.
	echo "Parsing JSON file..."
	updates_json="updates.json"
	testRun=$(jq -r .testRun "${updates_json}")
	if [ "$testRun" == "null" ]; then
		echo "JSON file does not contain 'testRun' key or it's null."
		exit 1
	fi

	# Increment run counter.
	echo "Incrementing testRun counter..."
	testRun=$((testRun + 1))
	jq ".testRun = ${testRun}" "${updates_json}" > "${updates_json}.tmp" && mv "${updates_json}.tmp" "${updates_json}"

	echo "Committing changes..."
	git add "${updates_json}"
	git commit -m "Increment testRun counter to ${testRun} for ${project} project."
	git push origin master
done

echo "Done. ✅"
