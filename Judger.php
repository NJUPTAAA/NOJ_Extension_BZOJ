<?php

namespace App\Babel\Extension\bzoj;

use App\Babel\Submit\Curl;
use App\Models\Submission\SubmissionModel;
use App\Models\JudgerModel;
use KubAT\PhpSimple\HtmlDomParser;
use Exception;

class Judger extends Curl
{

    public $verdict = [
        'Accepted' => 'Accepted',
        'Presentation_Error' => 'Presentation Error',
        'Wrong_Answer' => 'Wrong Answer',
        'Time_Limit_Exceed' => 'Time Limit Exceed',
        'Memory_Limit_Exceed' => 'Memory Limit Exceed',
        'Output_Limit_Exceed' => 'Output Limit Exceeded',
        'Runtime_Error' => 'Runtime Error',
        'Compile_Error' => 'Compile Error',
    ];
    private $inited = [];
    private $status = [];
    private $submissionModel, $judgerModel;


    public function __construct()
    {
        $this->submissionModel = new SubmissionModel();
        $this->judgerModel = new JudgerModel();
    }

    public function judge($row)
    {
        $handle = $this->judgerModel->detail($row['jid'])['handle'];
        if (!in_array($handle, $this->inited)) {
            $this->appendStatus($handle);
        }
        if (!isset($this->status[$row['remote_id']])) {
            $this->appendStatus($handle, $row['remote_id']);
            if (!isset($this->status[$row['remote_id']])) {
                return;
            }
        }
        $sub = [];

        $status = $this->status[$row['remote_id']];
        if (!isset($this->verdict[$status['verdict']])) {
            return;
        }
        $sub['verdict'] = $this->verdict[$status['verdict']];

        if ($sub['verdict'] == 'Compile Error') {
            $response = $this->grab_page([
                'site' => 'https://www.lydsy.com/JudgeOnline/ceinfo.php?sid=' . $row['remote_id'],
                'oj' => 'bzoj',
                'handle' => $handle,
            ]);
            assert(preg_match('/<pre>([\s\S]*)<\/pre>/', $response, $match));
            $sub['compile_info'] = html_entity_decode($match[1], ENT_QUOTES);
        }

        $sub["score"] = $sub['verdict'] == "Accepted" ? 1 : 0;
        if (!preg_match('/^(\d+) ms$/', $status['time'], $match)) throw new Exception('Time format error.');
        $sub['time'] = $match[1];
        if (!preg_match('/^(\d+) kb$/', $status['memory'], $match)) throw new Exception('Memory format error.');
        $sub['memory'] = $match[1];
        $sub['remote_id'] = $row['remote_id'];

        $this->submissionModel->updateSubmission($row['sid'], $sub);
    }

    private function appendStatus($handle, $top = null)
    {
        $url = 'https://www.lydsy.com/JudgeOnline/status.php?user_id=' . urlencode($handle);
        if ($top) $url .= '&top=' . $top;
        $table = HtmlDomParser::file_get_html($url)->find('table', 2);
        foreach($table->find('tr') as $tr) {
            $this->status[$tr->children(0)->innertext] = [ // Overrides even exists
                'verdict' => $tr->children(3)->text(),
                'memory' => $tr->children(4)->text(),
                'time' => $tr->children(5)->text(),
            ];
        }
    }
}
