<?php
namespace App\Babel\Extension\bzoj;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\OJModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;

class Crawler extends CrawlerBase
{
    public $oid = null;
    private $cached;
    private $cachedFiles = [];
    /**
     * Initial
     *
     * @return Response
     */
    public function start($conf)
    {
        $action=isset($conf["action"])?$conf["action"]:'crawl_problem';
        $con=isset($conf["con"])?$conf["con"]:'all';
        $this->cached = isset($conf["cached"]) ? $conf["cached"] : false;
        $this->oid=OJModel::oid('bzoj');

        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }

        if ($action=='judge_level') {
            $this->judge_level();
        } else {
            $this->crawl($con, $action == 'update_problem');
        }
    }

    public function judge_level()
    {
        // TODO
    }

    private function getUrlByPage($page, $path)
    {
        if (preg_match('/^\w+:/', $path)) return $path;
        $slash = strpos($page, '/', strpos($page, '://') + 3);
        if ($slash === false) $domain = $page;
        else $domain = substr($page, 0, $slash);
        if ($path[0] == '/') {
            if ($path[1] == '/') return substr($page, 0, strpos($page, ':') + 1) . $path;
            return $domain . $path;
        }
        $hash1 = strpos($page, '#');
        $hash2 = strpos($page, '?');
        $hash = min($hash1 === false ? 999 : $hash1, $hash2 === false ? 999 : $hash2);
        if ($hash === 999) $hash = -1;
        else $hash = strlen($page) - $hash - 1;
        return ($slash === false ? $page . '/' : substr($page, 0, $pos = strrpos($page, '/', $hash) + 1)) . $path;
    }

    private function cacheFile($href)
    {
        $href = trim($href);
        if (!file_exists('public/external/bzoj')) {
            mkdir('public/external/bzoj', 0755);
        }
        if (substr($href, 0, 21) == 'http://www.lydsy.com/') $href = substr($href, 20);
        if (substr($href, 0, 22) == 'https://www.lydsy.com/') $href = substr($href, 21);
        $cached = $this->cached;
        if (!$cached) {
            if (in_array($href, $this->cachedFiles)) {
                $cached = true;
            } else {
                $this->cachedFiles[] = $href;
            }
        }
        if (substr($href, 0, 42) == 'http://begin.lydsy.com/JudgeOnline/upload/') { // BZOJ5051
            if (!file_exists('public/external/bzoj/begin')) {
                mkdir('public/external/bzoj/begin', 0755);
            }
            $local = base_path('public/external/bzoj/begin/' . substr($href, 42));
            if (!$cached || !file_exists($local)) {
                file_put_contents(
                    $local,
                    file_get_contents($href)
                );
            }
            $path = '/external/bzoj/begin/' . substr($href, 42);
        } else if (substr($href, 0, 20) == '/JudgeOnline/upload/') {
            $pos = strpos($href, '/', 20);
            $_href = $href;
            if ($pos === false) {
                $href = '/JudgeOnline/upload/_/' . substr($href, 20);
                $pos = 21;
            }
            $dir = substr($href, 20, $pos - 20);
            $tmp = str_replace('/', '_', substr($href, strpos($href, '/', 20) + 1)); // I don't think it will have any problem
            if (!file_exists('public/external/bzoj/' . $dir)) {
                mkdir('public/external/bzoj/' . $dir, 0755);
            }
            $local = base_path('public/external/bzoj/' . $dir . '/' . $tmp);
            if (!$cached || !file_exists($local)) {
                file_put_contents(
                    $local,
                    file_get_contents('https://www.lydsy.com' . $_href)
                );
            }
            $path = '/external/bzoj/' . $dir . '/' . $tmp;
        } else if (substr($href, 0, 7) == 'images/') {
            $tmp = str_replace('/', '_', substr($href, 7)); // I don't think it will have any problem
            $local = base_path('public/external/bzoj/' . $tmp);
            if (!$cached || !file_exists($local)) {
                file_put_contents(
                    $local,
                    file_get_contents('https://www.lydsy.com/JudgeOnline/' . $href)
                );
            }
            $path = '/external/bzoj/' . $tmp;
        } else {
            return '';
        }
        return $path;
    }

    private function _crawl($con, $incremental)
    {
        $problemModel = new ProblemModel();
        if ($incremental && !empty($problemModel->basic($problemModel->pid('BZOJ' . $con)))) {
            return;
        }
        $updstr = $incremental ? 'Updat' : 'Crawl';
        $this->line("<fg=yellow>${updstr}ing: BZOJ$con.</>");
        $res = Requests::get("https://www.lydsy.com/JudgeOnline/problem.php?id=$con");
        $res = preg_replace_callback("/< *img([^>]*)src *= *[\"\\']?([^\"\\'>]*)([^>]*)>/si", function ($match) {
            $href = trim($match[2]);
            if ($href == 'image/logo.png') return '';
            $rslt = $this->cacheFile($href);
            if (!$rslt) {
                $this->line("  <bg=red;fg=white> Exception </> : Unknown picture href: $href");
                $rslt = $href;
            }
            return "<img{$match[1]}src=\"$rslt\"{$match[3]}>";
        }, $res->body);
        if ($con == 1972) $res = str_replace('<h2>Input', '</div></div></div></div></div></div></div><h2>Input', $res); // ....
        $dom = HtmlDomParser::str_get_html($res, true, true, DEFAULT_TARGET_CHARSET, false);
        $title = $dom->find('title', 0);
        if (!$title) {
            if (strpos($res, 'This problem is in Contest(s) below:') !== false) {
                $this->line("  <bg=red;fg=white> Exception </> : Problem is in some contest(s).");
            } else {
                $this->line("  <bg=red;fg=white> Exception </> : Unknown problem state.");
            }
            return;
        }
        if ($title->innertext == 'Please contact lydsy2012@163.com!') {
            $this->line('<fg=red>Problem not exist or no permission.</>');
            return;
        }
        $info = $title->next_sibling();
        if (!preg_match('/Time Limit:.*?>(\d+) Sec.*?>(\d+) MB.*?Solved.*?>(\d+)/', $info->innertext, $match)) {
            $this->line("  <bg=red;fg=white> Exception </> : Fetching info failed.");
        }
        $this->pro['pcode'] = 'BZOJ' . $con;
        $this->pro['OJ'] = $this->oid;
        $this->pro['contest_id'] = null;
        $this->pro['index_id'] = $con;
        $this->pro['origin'] = "https://www.lydsy.com/JudgeOnline/problem.php?id=$con";
        $this->pro['title'] = '';
        $this->pro['time_limit'] = $match[1] * 1000;
        $this->pro['memory_limit'] = $match[2] * 1024;
        $this->pro['solved_count'] = $match[3];
        $this->pro['input_type'] = 'standard input';
        $this->pro['output_type'] = 'standard output';
        $this->pro['description'] = '';
        $this->pro['input'] = '';
        $this->pro['output'] = '';
        $this->pro['note'] = '';
        $this->pro['source'] = '';
        $this->pro['sample'] = [];
        $this->pro['file'] = 0;
        $this->pro['file_url'] = null;

        $sampleWarning = 0;

        $mp = [
            'Description' => 'description',
            'Input' => 'input',
            'Output' => 'output',
            'Sample Input' => 'sample_input',
            'Sample Output' => 'sample_output',
            'HINT' => 'note',
            'Source' => 'source',
        ];
        foreach ($dom->find('h2') as $h2) {
            if ($this->pro['title'] == '') {
                $prefix = $con . ': ';
                if (substr($h2->innertext, 0, strlen($prefix)) === $prefix) {
                    $this->pro['title'] = substr($h2->innertext, strlen($prefix));
                } else {
                        $this->pro['title'] = $h2->innertext;
                        $this->line("  <bg=yellow;fg=black> Warning </> : Title format incorrect: $title");
                }
            } else {
                $name = $h2->innertext;
                if (isset($mp[$name])) {
                    $key = $mp[$name];
                    if ($con == 5402 && $key == 'input') $key = 'description'; // ****
                    if ($key) {
                        $content = $h2->next_sibling();
                        if ($key == 'source') {
                            $a = $content->find('a', 0);
                            $this->pro['source'] = $a->innertext;
                            if ($this->pro['source']) $this->pro['origin'] = $this->getUrlByPage($this->pro['origin'], $a->href);
                        } else {
                            $file = false;
                            foreach ($content->find('a') as $a) {
                                if (trim($a->href)) {
                                    $this->line("  <bg=yellow;fg=black> Warning </> : Found href in $name: " . $a->href);
                                    if ($key == 'description') {
                                        if ($rslt = $this->cacheFile($a->href)) {
                                            $this->pro['file'] = 1;
                                            $this->pro['file_url'] = $rslt;
                                            $file = true;
                                        } else {
                                            $this->line("  <bg=yellow;fg=black> Warning </> : Failed caching file.");
                                        }
                                    }
                                }
                            }
                            if ($file) continue;
                            if ($key[0] == 's') { // sample
                                $text = trim(preg_replace('/\s*<br ?\/?>\r?\n?\s*/', "\n", $content->children(0)->innertext));
                                if ($count = preg_match_all('/【?(?:(?:样例)?输[入出](?:样例)?|样例|Input|Output) *([1-9一二三四五六七八九])】?：?/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                                    $matches[$count][0][1] = strlen($text);
                                    for ($i = 0; $i < $count; ++$i) {
                                        $id = strpos('一二三四五六七八九', $matches[$i][1][0]);
                                        $from = $matches[$i][0][1] + strlen($matches[$i][0][0]);
                                        $str = substr($text, $from, $matches[$i + 1][0][1] - $from);
                                        $index = $id === false ? $matches[$i][1][0] - 1 : $id / 3;
                                        while (isset($this->pro['sample'][$index][$key])) ++$index;
                                        $this->pro['sample'][$index][$key] = trim($str);
                                    }
                                } else if ($text) {
                                    $this->pro['sample'][0][$key] = $text;
                                    if (strpos($text, '样例') !== false || strpos($text, '输') !== false || strpos($text, '【') !== false) {
                                        if (++$sampleWarning >= 2) $this->line("  <bg=yellow;fg=black> Warning </> : $con may have multi samples.");
                                    }
                                }
                            } else {
                                if ($key == 'note') {
                                    $empty = true;
                                    foreach($content->children() as $node) {
                                        if (trim($node->text()) != '') {
                                            $empty = false;
                                            break;
                                        }
                                    }
                                    if ($empty) continue;
                                }
                                $this->pro[$key] = trim($content->innertext);
                            }
                        }
                    }
                } else {
                    $this->line("  <bg=yellow;fg=black> Warning </> : Unknown section: $name");
                }
            }
        }

        if (empty($this->pro['source'])) $this->pro['source'] = $this->pro['pcode'];
        $this->pro['note'] = preg_replace_callback("/< *a([^>]*)href *= *[\"\\']?([^\"\\'>]*)([^>]*)>/si", function ($match) use ($con) {
            return "<a{$match[1]}href=\"" . $this->getUrlByPage("https://www.lydsy.com/JudgeOnline/problem.php?id=$con", trim($match[2])) . "\"{$match[3]}>";
        }, $this->pro['note']);

        foreach ($this->pro['sample'] as &$sample) {
            if (!isset($sample['sample_input'])) $sample['sample_input'] = null;
            if (!isset($sample['sample_output'])) $sample['sample_output'] = null;
        }

        $problem = $problemModel->pid($this->pro['pcode']);

        if ($problem) {
            $problemModel->clearTags($problem);
            $new_pid = $this->updateProblem($this->oid);
        } else {
            $new_pid = $this->insertProblem($this->oid);
        }

        // $problemModel->addTags($new_pid, $tag); // not present

        $this->line("<fg=green>${updstr}ed:  BZOJ$con.</>");
    }

    public function crawl($con, $incremental)
    {
        if ($con == 'all') {
            $res = Requests::get('https://www.lydsy.com/JudgeOnline/problemset.php');
            preg_match('/>(\d+)<\/a><\/h3/', $res->body, $match);
            $max = ($match[1] + 10) * 100; // consume last page is full
            for ($id = 1000; $id < $max; ++$id) {
                $this->_crawl($id, $incremental);
            }
            return;
        }
        $this->_crawl($con, $incremental);
    }
}
