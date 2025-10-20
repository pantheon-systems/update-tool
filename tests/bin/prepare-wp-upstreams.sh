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

# Check for GH_TOKEN.
echo "Checking for GH_TOKEN..."
if [ -z "${GH_TOKEN}" ]; then
	echo "GH_TOKEN is not set."
	exit 1
fi
git config --global credential.helper store
echo "https://${GH_TOKEN}@github.com" > ~/.git-credentials

echo "Getting configuration file..."
config="${GITHUB_WORKSPACE}/tests/fixtures/home/test-configuration.yml"
projects=("wp" "wpms")

mkdir -p "${GITHUB_WORKSPACE}/work/"
working_copy_path="${GITHUB_WORKSPACE}/work/"
for project in "${projects[@]}"; do
	echo "Preparing ${project}..."

	# Change directory to the project path.
	cd "${working_copy_path}" || { echo "Failed to change directory to ${working_copy_path}"; exit 1; }

	# Fiddle with the repo URL.
	repo_ssh_url=$(yq e ".projects.${project}.repo" "${config}")
	# Insert GH_TOKEN into the URL for HTTPS cloning.
	repo_url=$(echo "${repo_ssh_url}" | sed -e "s|git@github.com:\(.*\)\.git|https://${GH_TOKEN}@github.com/\1.git|")

	# Clone the project.
	echo "Cloning ${project} from ${repo_url}..."
	git clone "${repo_url}" "${working_copy_path}/${project}"
	cd "${project}" || { echo "Failed to change directory to ${project}"; exit 1; }
	branch="master"
	echo "Checking out ${branch} branch..."
	git checkout "${branch}"
	echo "Checked out ${project}."
done

function update_json() {
	wp_dir="${working_copy_path}wp"
	wpms_dir="${working_copy_path}wpms"
	if [ -d "${wp_dir}" ] && [ -d "${wpms_dir}" ]; then
		echo "Local working copies validated."
	else
		echo "One or both wp/wpms directories do not exist."
		exit 1
	fi

	git -C "${wp_dir}" remote add wpms-fixture "${wpms_dir}"
	git -C "${wp_dir}" fetch wpms-fixture

	echo "Checking if wp is ahead of wpms..."
	if git -C "${wp_dir}" log --oneline wpms-fixture/master..origin/master; then
		echo "wp fixture is already ahead of wpms fixture. Skipping json update."
	else
		cd "${wp_dir}"
		# Parse the JSON file.
		echo "Parsing JSON file..."
		updates_json="${wp_dir}/updates.json"
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
		git push origin ${branch}
	fi
}

update_json
echo "Done. ✅"
