<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class DiscordNotify extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'Sends a Discord webhook notification when a survey response is completed.';
    static protected $name = 'DiscordNotify';

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
        'showAnswers' => array(
            'type' => 'boolean',
            'label' => 'Include response answers in the message',
            'help' => 'Turn off to only post survey name and response ID, no answer content.',
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

        $fields = array(
            array('name' => 'Survey ID',   'value' => (string) $surveyId,   'inline' => true),
            array('name' => 'Response ID', 'value' => (string) $responseId, 'inline' => true),
        );

        if ((bool) $this->get('showSubmitDate', null, null, true)) {
            $fields[] = array('name' => 'Submitted', 'value' => date('Y-m-d H:i:s'), 'inline' => true);
        }

        if ((bool) $this->get('showIpAddress', null, null, false)) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
            $fields[] = array('name' => 'IP address', 'value' => $ip, 'inline' => true);
        }

        if ((bool) $this->get('showAnswers', null, null, true)) {
            $answerFields = $this->getAnswerFields($surveyId, $responseId, $language);
            $fields = array_merge($fields, $answerFields);
        }

        $botUsername = trim((string) $this->get('botUsername', null, null, 'LimeSurvey'));
        $colorHex = ltrim(trim((string) $this->get('embedColor', null, null, '2ECC71')), '#');
        $colorDecimal = ctype_xdigit($colorHex) ? hexdec($colorHex) : 3066993;

        $payload = array(
            'username' => $botUsername !== '' ? $botUsername : 'LimeSurvey',
            'embeds' => array(
                array(
                    'title'       => 'New survey response',
                    'description' => $surveyTitle,
                    'color'       => $colorDecimal,
                    'fields'      => $fields,
                    'timestamp'   => date('c'),
                ),
            ),
        );

        $this->postToDiscord($webhookUrl, $payload);
    }

    private function getAnswerFields($surveyId, $responseId, $language)
    {
        $fields = array();

        try {
            $response = \SurveyDynamic::model($surveyId)->findByPk($responseId);
            if (!$response) {
                return $fields;
            }

            $questions = \Yii::app()->db->createCommand()
                ->select('q.qid, q.gid, q.title, ql.question')
                ->from('{{questions}} q')
                ->join('{{question_l10ns}} ql', 'ql.qid = q.qid AND ql.language = :lang', array(':lang' => $language))
                ->where('q.sid = :sid AND q.parent_qid = 0', array(':sid' => $surveyId))
                ->queryAll();

            foreach ($questions as $question) {
                $column = $question['title'];
                $questionText = strip_tags((string) $question['question']);
                $value = $response->hasAttribute($column) ? $response->getAttribute($column) : null;

                if ($value === null || $value === '') {
                    continue;
                }

                $fields[] = array(
                    'name'   => mb_substr($questionText !== '' ? $questionText : $column, 0, 256),
                    'value'  => mb_substr((string) $value, 0, 1024),
                    'inline' => false,
                );
            }
        } catch (\Exception $e) {
            $log = \Yii::app()->basePath . '/../tmp/runtime/discordnotify.log';
            @file_put_contents($log, date('c') . ' error fetching answers: ' . $e->getMessage() . "\n", FILE_APPEND);
        }

        return $fields;
    }

    private function postToDiscord($url, $payload)
    {
        $json = json_encode($payload);

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
            $log = \Yii::app()->basePath . '/../tmp/runtime/discordnotify.log';
            @file_put_contents($log, date('c') . ' cURL error: ' . $error . "\n", FILE_APPEND);
        }
    }
}
