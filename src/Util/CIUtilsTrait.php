<?php
namespace Updatinate\Util;

trait CIUtilsTrait
{
    protected function cancelCurrentCIBuild()
    {
        $this->cancelCurrentCircleCIBuild();
    }

    protected function cancelCurrentCircleCIBuild()
    {
        $token = getenv('CIRCLE_TOKEN');
        $org = getenv('CIRCLE_PROJECT_USERNAME');
        $project = getenv('CIRCLE_PROJECT_REPONAME');
        $build_num = getenv('CIRCLE_BUILD_NUM');
        $vcs_type = 'github';

        $this->cancelCircleCIBuild($token, $org, $project, $build_num, $vcs_type);
    }

    protected function cancelCircleCIBuild($token, $org, $project, $build_num, $vcs_type = 'github')
    {
        if (!$token) {
          return;
        }

        $this->logger->notice("Cancelling Circle build.");
        $url = "https://circleci.com/api/v1.1/project/$vcs_type/$org/$project/$build_num/cancel";
        $this->circleCIAPI([], $url, $token);
    }

    protected function circleCIAPI($data, $url, $token)
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'pantheon/updatinate'
        ];

        $client = new \GuzzleHttp\Client();
        $res = $client->request('POST', $url, [
            'headers' => $headers,
            'auth' => [$token, ''],
            'json' => $data,
        ]);
        return $res->getStatusCode();
    }
}
