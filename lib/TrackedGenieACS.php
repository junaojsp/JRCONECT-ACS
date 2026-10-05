<?php
namespace App;

require_once __DIR__ . '/GenieACS.php';
require_once __DIR__ . '/ActionHistory.php';

/** Log command metadata around the existing client. No task payloads are stored. */
class TrackedGenieACS extends GenieACS
{
    private function tracked($deviceId, $action, $fields, $send)
    {
        $id = \acsActionStart((string)$deviceId, $action, $fields);
        try {
            $result = $send();
            \acsActionFinish($id, $result);
            $result['action_history_id'] = $id;
            return $result;
        } catch (\Throwable $error) {
            \acsActionFinish($id, []);
            throw $error;
        }
    }
    public function executeTask($deviceId, $taskName, $params = [])
    {
        return $this->tracked($deviceId, $taskName === 'reboot' ? 'reboot' : 'command', \acsActionFields($params),
            fn() => parent::executeTask($deviceId, $taskName, $params));
    }
    public function setParameterValues($deviceId, $parameters, $timeout = 3000)
    {
        return $this->tracked($deviceId, 'change', \acsActionFields($parameters),
            fn() => parent::setParameterValues($deviceId, $parameters, $timeout));
    }
    public function addRefreshTask($deviceId, $parameterPath)
    {
        return $this->tracked($deviceId, 'refresh', '', fn() => parent::addRefreshTask($deviceId, $parameterPath));
    }
    public function getParameterValues($deviceId, $parameterNames, $timeout = 3000)
    {
        return $this->tracked($deviceId, 'refresh', '', fn() => parent::getParameterValues($deviceId, $parameterNames, $timeout));
    }
    public function summonAndFetchAdminCredentials($deviceId)
    {
        return $this->tracked($deviceId, 'refresh', 'Credenciais administrativas', fn() => parent::summonAndFetchAdminCredentials($deviceId));
    }
    public function summonDevice($deviceId)
    {
        return $this->tracked($deviceId, 'summon', '', fn() => parent::summonDevice($deviceId));
    }
    public function refreshInform($deviceId)
    {
        return $this->tracked($deviceId, 'summon', '', fn() => parent::refreshInform($deviceId));
    }
}
