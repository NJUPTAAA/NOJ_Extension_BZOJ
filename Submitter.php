<?php
namespace App\Babel\Extension\bzoj;

use App\Babel\Submit\Curl;
use App\Models\CompilerModel;
use App\Models\ProblemModel;
use App\Models\JudgerModel;
use App\Models\OJModel;
use Illuminate\Support\Facades\Validator;
use KubAT\PhpSimple\HtmlDomParser;

class Submitter extends Curl
{
    protected $sub;
    public $post_data=[];
    protected $oid;
    protected $selectedJudger;

    public function __construct(& $sub, $all_data)
    {
        $this->sub=& $sub;
        $this->post_data=$all_data;
        $judger=new JudgerModel();
        $this->oid=OJModel::oid('bzoj');
        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }
        $judger_list=$judger->list($this->oid);
        $this->selectedJudger=$judger_list[array_rand($judger_list)];
    }

    private function _login()
    {
        $response = $this->grab_page([
            'site' => 'https://www.lydsy.com/JudgeOnline/',
            'oj' => 'bzoj',
            'handle' => $this->selectedJudger['handle'],
        ]);
        if (strpos($response, 'Logout') === false) {
            $this->login([
                'url' => 'https://www.lydsy.com/JudgeOnline/login.php',
                'data' => http_build_query([
                    'user_id' => $this->selectedJudger['handle'],
                    'password' => $this->selectedJudger['password'],
                ]),
                'oj' => 'bzoj',
                'handle' => $this->selectedJudger['handle'],
            ]);
        }
    }

    private function _submit()
    {
        $problem = new ProblemModel();
        $problem = $problem->basic($this->post_data['pid']);
        $compiler = new CompilerModel();
        $compiler = $compiler->detail($this->post_data['coid']);

        // In order to confirm remote id
        $id = substr(md5(uniqid(microtime(true), true)), 0, 6);
        if ($compiler['comp'] == 'pascal') {
            $pattern = "{ $id }\n";
        } else {
            $pattern = "/*$id*/\n";
        }

        $response = $this->post_data([
            'site' => 'https://www.lydsy.com/JudgeOnline/submit.php',
            'data' => http_build_query([
                'id' => $problem['index_id'],
                'language' => $compiler['lcode'],
                'source' => $pattern . $this->post_data['solution'],
            ]),
            'oj' => 'bzoj',
            'ret' => true,
            'follow' => true,
            'returnHeader' => false,
            'handle' => $this->selectedJudger['handle'],
        ]);

        $dom = HtmlDomParser::str_get_html($response);
        $table = $dom->find('table', 2);
        foreach ($table->find('tr') as $tr) {
            $td = $tr->children(0);
            if (is_numeric($remoteId = $td->innertext)) {
                $response = $this->grab_page([
                    'site' => "https://www.lydsy.com/JudgeOnline/showsource.php?id=$remoteId",
                    'oj' => 'bzoj',
                    'handle' => $this->selectedJudger['handle'],
                ]);
                if (strpos($response, $id) !== false) {
                    $this->sub['remote_id'] = $remoteId;
                    $this->sub['jid'] = $this->selectedJudger['jid'];
                    return;
                }
            }
        }
        sleep(1);
        throw new \Exception("Submission error");
    }

    public function submit()
    {
        $validator=Validator::make($this->post_data, [
            'pid' => 'required|integer',
            'coid' => 'required|integer',
            'solution' => 'required|max:65524', // reserve 12 bytes for pattern
        ]);

        if ($validator->fails()) {
            $this->sub['verdict']="System Error";
            return;
        }

        $this->_login();
        $this->_submit();
    }
}
