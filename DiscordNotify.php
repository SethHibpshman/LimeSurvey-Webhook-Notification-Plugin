<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class DiscordNotify extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'Sends a Discord webhook notification when a survey response is completed.';
    static protected $name = 'DiscordNotify';

    private $systemColumns = array(
        'id', 'token', 'submitdate', 'lastpage', 'startlanguage',
        'seed', 'startdate', 'datestamp', 'ipaddr', 'refurl',
    );

    protected $settings = array(
        'webhookUrl' => array(
            'type' => 'string',
            'label' => 'Discord webhook URL',
            'help' => 'Paste the webhook URL from your Discord channel integration settings.',
            'default' => '',
        ),
        'botUsername' => array(
            'type' => 'string',
            'label' => 'Bot display name',
            'help' => 'Name shown as the sender of the Discord message.',
            'default' => 'LimeSurvey',
        ),
        'embedColor' => array(
            'type' => 'string',
            'label' => 'Embed color (hex, no #)',
            'help' => 'e.g. 2ECC71 for green, E74C3C for red, 3498DB for blue.',
            'default' => '2ECC71',
        ),
        'embedTitle' => array(
            'type' => 'string',
            'label' => 'Embed title text',
            'default' => 'New survey response',
        ),
        'showSurveyId' => array(
            'type' => 'boolean',
            'label' => 'Include survey ID',
            'default' => true,
        ),
        'showResponseId' => array(
            'type' => 'boolean',
            'label' => 'Include response ID',
            'default' => true,
        ),
        'showAnswers' => array(
            'type' => 'boolean',
            'label' => 'Include response answers in the message',
            'help' => 'Turn off to only post survey name and IDs, no answer content.',
            'default' => true,
        ),
        'showSubmitDate' => array(
            'type' => 'boolean',
            'label' => 'Include submission date/time',
            'default' => true,
        ),
        'showIpAddress' => array(
            'type' => 'boolean',
            'label' => 'Include respondent IP address',
            'help' => 'Off by default for privacy. Useful for spotting duplicate/bot submissions.',
            'default' => false,
        ),
        'showAdminLink' => array(
            'type' => 'boolean',
            'label' => 'Include a link to view the response in LimeSurvey admin',
            'default' => false,
        ),
        'showTimestampFooter' => array(
            'type' => 'boolean',
            'label' => 'Show Discord embed timestamp',
            'help' => 'The small timestamp Discord renders in the corner of the embed.',
            'default' => true,
        ),
        'surveyFilter' => array(
            'type' => 'string',
            'label' => 'Only notify for these survey IDs',
            'help' => 'Comma-separated survey IDs (e.g. 864547,123456). Leave blank to notify for all surveys.',
            'default' => '',
        ),
    );

    public function init()
    {
        $this->subscribe('afterSurveyComplete');
    }

    public function afterSurveyComplete()
    {
        $webhookUrl = trim((string) $this->get('webhookUrl', null, null, ''));
        if ($webhookUrl === '') {
            return;
        }

        $event = $this->getEvent();
        $surveyId = $event->get('surveyId');
        $responseId = $event->get('responseId');

        $surveyFilter = trim((string) $this->get('surveyFilter', null, null, ''));
        if ($surveyFilter !== '') {
            $allowedIds = array_map('trim', explode(',', $surveyFilter));
            if (!in_array((string) $surveyId, $allowedIds, true)) {
                return;
            }
        }

        $surveyInfo = \Survey::model()->findByPk($surveyId);
        $surveyTitle = $surveyInfo ? $surveyInfo->currentLanguageSettings->surveyls_title : ('Survey ' . $surveyId);
        $language = $surveyInfo ? $surveyInfo->language : 'en';

        $fields = array();

        if ((bool) $this->get('showSurveyId', null, null, true)) {
            $fields[] = array('name' => 'Survey ID', 'value' => (string) $surveyId, 'inline' => true);
        }

        if ((bool) $this->get('showResponseId', null, null, true)) {
            $fields[] = array('name' => 'Response ID', 'value' => (string) $responseId, 'inline' => true);
        }

        if ((bool) $this->get('showSubmitDate', null, null, true)) {
            $fields[] = array('name' => 'Submitted', 'value' => date('Y-m-d H:i:s'), 'inline' => true);
        }

        if ((bool) $this->get('showIpAddress', null, null, false)) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
            $fields[] = array('name' => 'IP address', 'value' => $ip, 'inline' => true);
        }

        if ((bool) $this->get('showAdminLink', null, null, false)) {
            $link = $this->buildAdminLink($surveyId, $responseId);
            if ($link !== null) {
                $fields[] = array('name' => 'View response', 'value' => '[Open in LimeSurvey](' . $link . ')', 'inline' => false);
            }
        }

        if ((bool) $this->get('showAnswers', null, null, true)) {
            $answerFields = $this->getAnswerFields($surveyId, $responseId, $language);
            $fields = array_merge($fields, $answerFields);
        }

        $botUsername = trim((string) $this->get('botUsername', null, null, 'LimeSurvey'));
        $colorHex = ltrim(trim((string) $this->get('embedColor', null, null, '2ECC71')), '#');
        $colorDecimal = ctype_xdigit($colorHex) ? hexdec($colorHex) : 3066993;
        $embedTitle = trim((string) $this->get('embedTitle', null, null, 'New survey response'));

        $embed = array(
            'title'       => $embedTitle !== '' ? $embedTitle : 'New survey response',
            'description' => $surveyTitle,
            'color'       => $colorDecimal,
            'fields'      => $fields,
        );

        if ((bool) $this->get('showTimestampFooter', null, null, true)) {
            $embed['timestamp'] = date('c');
        }

        $payload = array(
            'username' => $botUsername !== '' ? $botUsername : 'LimeSurvey',
            'embeds'   => array($embed),
        );

        $this->postToDiscord($webhookUrl, $payload);
    }

    private function buildAdminLink($surveyId, $responseId)
    {
        try {
            $baseUrl = \Yii::app()->createAbsoluteUrl('admin/responses');
            // LimeSurvey expects sa/surveyid/id as path segments, not query params.
            $url = $baseUrl . '/sa/view/surveyid/' . $surveyId . '/id/' . $responseId;
            return $url;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getAnswerFields($surveyId, $responseId, $language)
    {
        $fields = array();
        $log = \Yii::app()->basePath . '/../tmp/runtime/discordnotify.log';

        try {
            $responseTable = '{{responses_' . $surveyId . '}}';
            $rawTableName = \Yii::app()->db->tablePrefix . 'responses_' . $surveyId;

            $columnsInfo = \Yii::app()->db->createCommand('SHOW COLUMNS FROM ' . $rawTableName)->queryAll();
            $answerColumns = array();
            foreach ($columnsInfo as $col) {
                $fieldName = $col['Field'];
                if (!in_array($fieldName, $this->systemColumns, true)) {
                    $answerColumns[] = $fieldName;
                }
            }

            if (empty($answerColumns)) {
                return $fields;
            }

            $row = \Yii::app()->db->createCommand()
                ->select(implode(', ', $answerColumns))
                ->from($responseTable)
                ->where('id = :id', array(':id' => $responseId))
                ->queryRow();

            if ($row === false) {
                return $fields;
            }

            // Build a code -> question text map. Response table columns are sometimes named
            // after the custom question code (e.g. "Q001") and sometimes after "Q" + the
            // internal question ID (e.g. "Q9"), depending on how the question was set up.
            // Index the map under both possible keys so either naming style resolves.
            $labelMap = array();
            $questions = \Yii::app()->db->createCommand()
                ->select('q.qid, q.title, ql.language, ql.question')
                ->from('{{questions}} q')
                ->join('{{question_l10ns}} ql', 'ql.qid = q.qid')
                ->where('q.sid = :sid', array(':sid' => $surveyId))
                ->queryAll();

            foreach ($questions as $question) {
                $text = strip_tags((string) $question['question']);
                if ($text === '') {
                    continue;
                }

                $isPreferredLanguage = ($question['language'] === $language);
                $keys = array($question['title'], 'Q' . $question['qid']);

                foreach ($keys as $key) {
                    if (!isset($labelMap[$key]) || $isPreferredLanguage) {
                        $labelMap[$key] = $text;
                    }
                }
            }

            foreach ($answerColumns as $column) {
                $value = isset($row[$column]) ? $row[$column] : null;

                if ($value === null || trim((string) $value) === '') {
                    continue;
                }

                $label = isset($labelMap[$column]) && $labelMap[$column] !== '' ? $labelMap[$column] : $column;

                $fields[] = array(
                    'name'   => mb_substr($label, 0, 256),
                    'value'  => mb_substr((string) $value, 0, 1024),
                    'inline' => false,
                );
            }
        } catch (\Exception $e) {
            @file_put_contents($log, date('c') . ' error fetching answers: ' . $e->getMessage() . "\n", FILE_APPEND);
        }

        return $fields;
    }

    private function postToDiscord($url, $payload)
    {
        $json = json_encode($payload);
        $log = \Yii::app()->basePath . '/../tmp/runtime/discordnotify.log';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        curl_exec($ch);

        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            @file_put_contents($log, date('c') . ' cURL error: ' . $error . "\n", FILE_APPEND);
        }
    }
}
