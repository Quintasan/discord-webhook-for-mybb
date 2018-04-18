<?php

/**
 * The MIT License (MIT)
 * 
 * Copyright (c) 2017 Łukasz Kodzis (Ryczypiór)
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software
 * and associated documentation files (the "Software"), to deal in the Software without restriction, 
 * including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, 
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, 
 * subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all copies or substantial 
 * portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, 
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. 
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, 
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE 
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
if (!class_exists('DiscordWebhook')) {

    class DiscordWebhook {

        protected $endpointURL = null;
        protected $mybb = null;

        protected function __construct($mybb, $fid, $suffix = '') {
            $this->mybb = $mybb;
            if (!$mybb->settings['discord_webhooks' . $suffix . '_enabled'] || empty($mybb->settings['discord_webhooks' . $suffix . '_forums'])) {
                throw new Exception('Plugin is not enabled');
            }
            $fids = explode(',', $mybb->settings['discord_webhooks' . $suffix . '_forums']);
            $ignoredfids = explode(',', $mybb->settings['discord_webhooks' . $suffix . '_ignored_forums']);
            if ((!in_array($fid, $fids) && $mybb->settings['discord_webhooks' . $suffix . '_forums'] != -1) || (in_array($fid, $ignoredfids)) || $mybb->settings['discord_webhooks' . $suffix . '_ignored_forums'] == -1) {
                throw new Exception('Board is not enabled');
            }
            $is_member = is_member($mybb->settings['discord_webhooks' . $suffix . '_ignored_usergroups']);
            if (!empty($is_member)) {
                throw new Exception('User belongs to disabled usergroup');
            }
            if (preg_match('/^\s*https?:\/\/(ptb\.)?discordapp\.com\/api\/webhooks\//i', $mybb->settings['discord_webhooks' . $suffix . '_url']) == 0) {
                throw new Exception('Invalid Discord Webhook URL');
            }
            $this->endpointURL = $mybb->settings['discord_webhooks' . $suffix . '_url'];
        }

        private function sendCURL($message, $webhook) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $webhook);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
            $result = curl_exec($ch);
            // Check for errors and display the error message
            if ($errno = curl_errno($ch)) {
                $error_message = curl_strerror($errno);
                throw new \Exception("cURL error ({$errno}):\n {$error_message}");
            }
            $json_result = json_decode($result, true);
            if (($httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE)) != 204) {
                throw new \Exception($httpcode . ':' . $result);
            }
            curl_close($ch);
            return $result;
        }

        private function sendSocket($message, $webhook) {
            $parsed = parse_url($webhook);
            $result = '';
            if ($f = fsockopen((($parsed['scheme'] == 'https') ? 'ssl://' : '') . $parsed['host'], (($parsed['scheme'] == 'https') ? 443 : 80), $errno, $errmsg, 30)) {
                $out = "POST " . $parsed['path'] . " HTTP/1.0\r\n";
                $out .= "Host: " . $parsed['host'] . "\r\n";
                $out .= "Content-Type: application/json\r\n";
                $out .= "Content-Length: " . strlen($message) . "\r\n\r\n";
                $out .= $message;
                fwrite($f, $out);
                while (!feof($f)) {
                    $result .= fread($f, 4096);
                }
                fclose($f);
            }
            return $result;
        }

        protected function send($username, $message, $avatar = null, $embeds = null, $tts = false) {
            $push = json_encode(array(
                'username' => $username,
                'avatar_url' => $avatar,
                'content' => $message,
                'embeds' => $embeds,
                'tts' => $tts,
                    ), JSON_NUMERIC_CHECK);
            if ($mybb->settings['discord_webhooks' . $suffix . '_usesocket']) {
                $this->sendSocket($push, $this->endpointURL);
            } else {
                $this->sendCURL($push, $this->endpointURL);
            }
            return $this;
        }

        protected function getFullUrl($uri) {
            /* $proto = 'http://';
              if ($_SERVER["https"] == "on" || $_SERVER["https"] == 1 || $_SERVER['SERVER_PORT'] == 443) {
              $proto = 'https://';
              }
              $ret = $proto . $_SERVER['HTTP_HOST'] . $uri;
             * 
             */
            $ret = $this->mybb->settings['bburl'] . "/" . str_replace('&amp;', '&', $uri);
            return $ret;
        }

        protected function rn($msg) {
            return preg_replace(array('/\\\n/is', '/\\\r/is'), array("\n", "\r"), $msg);
        }

        protected function escapeMarkdown($msg) {
            $from = array(
                '*',
                '-',
                '_',
                '`',
            );
            $to = array(
                '\\*',
                '\\-',
                '\\_',
                '\\`',
            );
            return str_replace($from, $to, $msg);
        }

        protected function bbCodeToMarkdown($msg) {
            $from = array(
                '|\[b\](.+?)\[/b]|is',
                '|\[i\](.+?)\[/i]|is',
                '|\[u\](.+?)\[/u]|is',
                '|\[s\](.+?)\[/s]|is',
                '|\[code\](.+?)\[/code]|is',
                '|\[php\](.+?)\[/php]|is',
                '|\[url=\"?(.+?)\"?\](.+?)\[/url]|is',
                '|\[([^\]]+?)(=[^\]]+?)?\](.+?)\[/\1\]|is',
            );
            $to = array(
                '**$1**',
                '*$1*',
                '__$1__',
                '--$1--',
                '```$1```',
                "```php\n$1```",
                '[$2]($1)',
                '',
            );
            return preg_replace($from, $to, $msg);
        }

        protected function formatMessage($msg) {
            $ret = $this->rn($msg);
            $ret = $this->escapeMarkdown($ret);
            $ret = $this->bbCodeToMarkdown($ret);
            return $ret;
        }

        protected function getAvatarUrlUpload($avatar) {
            $avatar = preg_replace('/^\.\//', '', $avatar);
            $ret = $this->getFullUrl($avatar);
            return $ret;
        }

        protected function getAvatarUrlDefault($avatar) {
            return $avatar;
        }

        protected function getColorIntFromHex($color) {
            $color = trim(str_replace('#', '', $color));
            return hexdec($color);
        }

        protected function getReplaceTable($uid) {
            global $mybb, $db, $lang;
            $replace = [];
            $query = $db->simple_select("userfields", "*", "ufid='{$uid}'");
            $result = $db->fetch_array($query);
            if (!empty($result)) {
                foreach ($result as $k => $v) {
                    if (preg_match('/^fid.+/', $k)) {
                        $replace[$k] = $v;
                    }
                }
            }
            $query = $db->simple_select("profilefields", "fid, name");
            while ($result = $db->fetch_array($query)) {
                $replace['@' . $result['name']] = $replace['fid' . $result['fid']];
            }
            return $replace;
        }

        static public function newThread($entry, $suffix = '') {
            global $mybb, $db, $lang;
            if ($mybb->settings['discord_webhooks' . $suffix . '_new_thread_enabled']) {
                $lang->load('discord_webhooks');
                $color = $mybb->settings['discord_webhooks' . $suffix . '_new_thread_color'];
                if (empty($color)) {
                    $color = '#aaaaaa';
                }
                //require_once MYBB_ROOT . "inc/class_parser.php";
                try {
                    if ($entry->return_values['visible'] == 1) {
                        $discordWebhook = new self($mybb, $entry->data['fid'], $suffix);
                        $botname = $mybb->settings['discord_webhooks' . $suffix . '_botname'];
                        $url = '';
                        $replace = [];
                        $user = null;
                        if (!empty($entry->post_insert_data['uid'])) {
                            $query = $db->simple_select("users", "*", "uid='{$entry->post_insert_data['uid']}'");
                            $replace = $user = $db->fetch_array($query);
                        }
                        $replace = array_merge($replace, $discordWebhook->getReplaceTable($entry->post_insert_data['uid']), [
                            'username' => $entry->post_insert_data['username'],
                            'posttitle' => $entry->post_insert_data['subject'],
                            'threadtitle' => $entry->post_insert_data['subject'],
                            'boardname' => '',
                            'url' => $discordWebhook->getFullUrl(get_thread_link($entry->return_values['tid'])),
                        ]);
                        $message = $mybb->settings['discord_webhooks' . $suffix . '_new_thread_message'];
                        if (empty($message)) {
                            $message = $lang->discord_webhooks_new_thread_message_value;
                        }
                        $thread = array();
                        if ($entry->return_values['tid'] > 0) {
                            $query = $db->simple_select("threads", "*", "tid='{$entry->return_values['tid']}'");
                            $thread = $db->fetch_array($query);
                        }
                        if ($thread['fid'] > 0) {
                            $query = $db->simple_select("forums", "name", "fid='{$thread['fid']}'");
                            $replace['boardname'] = $db->fetch_field($query, "name");
                        }
                        $replace['threadtitle'] = str_replace(['{', '}'], ['\\{', '\\}'], $entry->thread_insert_data['subject']);
                        $replace['posttitle'] = str_replace(['{', '}'], ['\\{', '\\}'], $entry->post_insert_data['subject']);
                        foreach ($replace as $from => $to) {
                            $message = str_replace('{' . $from . '}', $to, $message);
                            $botname = str_replace('{' . $from . '}', $to, $botname);
                        }
                        $message = str_replace(['\\{', '\\}'], ['{', '}'], $message);
                        $message = $discordWebhook->rn($message);

                        $embeds = null;
                        if (!empty($mybb->settings['discord_webhooks' . $suffix . '_show'])) {
                            $thumbnail = null;
                            $title = $entry->post_insert_data['subject'];
                            $url = $replace['url'];
                            $msg = $discordWebhook->formatMessage($entry->post_insert_data['message']);
                            $author = array(
                                'name' => $entry->post_insert_data['username'],
                                'icon_url' => '', //$avatar
                            );
                            $avatar = '';
                            if (!empty($user)) {
                                $author['url'] = $discordWebhook->getFullUrl('member.php?action=profile&uid=' . $entry->post_insert_data['uid']);
                                $avatar = $user['avatar'];
                                $avatartype = $user['avatartype'];
                                if (!empty($avatar)) {
                                    /*
                                     * $method = 'getAvatarUrl' . ucfirst($avatartype);
                                      if (!method_exists($this, $method)) {
                                      $method = 'getAvatarUrlDefault';
                                      }
                                      $avatar = $discordWebhook->$method($avatar);
                                     */
                                    if ($avatartype === 'upload') {
                                        $avatar = $discordWebhook->getAvatarUrlUpload($avatar);
                                    } else {
                                        $avatar = $discordWebhook->getAvatarUrlDefault($avatar);
                                    }
                                } else {
                                    $avatar = '';
                                }
                            }
                            $limit = 1000;
                            if ($mybb->settings['discord_webhooks' . $suffix . '_show'] == 1) {
                                $limit = 100;
                            } else {
                                $thumbnail = array(
                                    'url' => $avatar,
                                );
                            }
                            if (mb_strlen($msg, 'UTF-8') > $limit) {
                                $msg = mb_strcut($msg, 0, $limit, 'UTF-8') . '...';
                            }
                            $color = $discordWebhook->getColorIntFromHex($color);
                            $embeds = array(
                                array(
                                    'type' => "rich",
                                    'title' => $title,
                                    'description' => $msg,
                                    'url' => $url,
                                    'color' => $color,
                                    'author' => $author,
                                    'thumbnail' => $thumbnail,
                                ),
                            );
                        }
                        $discordWebhook->send($botname, $message, null, $embeds);
                    }
                } catch (Exception $ex) {
                    
                }
            }
        }

        static public function newPost($entry, $suffix = '') {
            global $mybb, $db, $lang;
            if ($mybb->settings['discord_webhooks' . $suffix . '_new_post_enabled']) {
                $lang->load('discord_webhooks');
                $color = $mybb->settings['discord_webhooks' . $suffix . '_new_post_color'];
                if (empty($color)) {
                    $color = '#ffffff';
                }
                require_once MYBB_ROOT . "inc/class_parser.php";
                try {
                    if ($entry->return_values['visible'] == 1) {
                        $botname = $mybb->settings['discord_webhooks' . $suffix . '_botname'];
                        $discordWebhook = new self($mybb, $entry->data['fid'], $suffix);
                        $url = '';
                        $replace = [];
                        $user = null;
                        $post = array();
                        if (!empty($entry->return_values['pid'])) {
                            $query = $db->simple_select("posts", "*", "pid='{$entry->return_values['pid']}'");
                            $post = $user = $db->fetch_array($query);
                        }
                        if (!empty($post['uid'])) {
                            $query = $db->simple_select("users", "*", "uid='{$post['uid']}'");
                            $replace = $user = $db->fetch_array($query);
                        }
                        $replace = array_merge($replace, $discordWebhook->getReplaceTable($post['uid']), [
                            'username' => (!empty($user) ? $user['username'] : $entry->post_insert_data['username']),
                            'posttitle' => $post['subject'],
                            'threadtitle' => $entry->post_insert_data['subject'],
                            'boardname' => '',
                            'url' => $discordWebhook->getFullUrl(get_post_link($post['pid'], $post['tid']).'#pid'.$post['pid']),
                        ]);
                        $message = $mybb->settings['discord_webhooks' . $suffix . '_new_post_message'];
                        if (empty($message)) {
                            $message = $lang->discord_webhooks_new_post_message_value;
                        }
                        if ($post['fid'] > 0) {
                            $query = $db->simple_select("forums", "name", "fid='{$post['fid']}'");
                            $replace['boardname'] = $db->fetch_field($query, "name");
                        }
                        if ($post['tid'] > 0) {
                            $query = $db->simple_select("threads", "subject", "tid='{$post['tid']}'");
                            $replace['threadtitle'] = $db->fetch_field($query, "subject");
                        }
                        $replace['threadtitle'] = str_replace(['{', '}'], ['\\{', '\\}'], $replace['threadtitle']);
                        $replace['posttitle'] = str_replace(['{', '}'], ['\\{', '\\}'], $replace['posttitle']);
                        foreach ($replace as $from => $to) {
                            $message = str_replace('{' . $from . '}', $to, $message);
                            $botname = str_replace('{' . $from . '}', $to, $botname);
                        }
                        $message = str_replace(['\\{', '\\}'], ['{', '}'], $message);
                        $message = $discordWebhook->rn($message);

                        $embeds = null;
                        if (!empty($mybb->settings['discord_webhooks' . $suffix . '_show'])) {
                            $thumbnail = null;
                            $title = $entry->post_insert_data['subject'];
                            $url = $replace['url'];
                            $msg = $discordWebhook->formatMessage($entry->post_insert_data['message']);
                            $author = array(
                                'name' => $entry->post_insert_data['username'],
                                'icon_url' => '', //$avatar
                            );
                            $avatar = '';
                            if (!empty($user)) {
                                $author['url'] = $discordWebhook->getFullUrl('member.php?action=profile&uid=' . $entry->post_insert_data['uid']);
                                $avatar = $user['avatar'];
                                $avatartype = $user['avatartype'];
                                if (!empty($avatar)) {
                                    /*
                                     * $method = 'getAvatarUrl' . ucfirst($avatartype);
                                      if (!method_exists($this, $method)) {
                                      $method = 'getAvatarUrlDefault';
                                      }
                                      $avatar = $discordWebhook->$method($avatar);
                                     * 
                                     */
                                    if ($avatartype === 'upload') {
                                        $avatar = $discordWebhook->getAvatarUrlUpload($avatar);
                                    } else {
                                        $avatar = $discordWebhook->getAvatarUrlDefault($avatar);
                                    }
                                } else {
                                    $avatar = '';
                                }
                            }
                            $limit = 1000;
                            if ($mybb->settings['discord_webhooks' . $suffix . '_show'] == 1) {
                                $limit = 100;
                            } else {
                                $thumbnail = array(
                                    'url' => $avatar,
                                );
                            }
                            if (mb_strlen($msg, 'UTF-8') > $limit) {
                                $msg = mb_strcut($msg, 0, $limit, 'UTF-8') . '...';
                            }
                            $color = $discordWebhook->getColorIntFromHex($color);
                            $embeds = array(
                                array(
                                    'type' => "rich",
                                    'title' => $title,
                                    'description' => $msg,
                                    'url' => $url,
                                    'color' => $color,
                                    'author' => $author,
                                    'thumbnail' => $thumbnail,
                                ),
                            );
                        }
                        $discordWebhook->send($botname, $message, null, $embeds);
                    }
                } catch (Exception $ex) {
                    
                }
            }
        }

        static public function __callStatic($name, $arguments) {
            $reflection = new ReflectionClass('DiscordWebhook');
            $staticMethods = $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC);
            foreach ($staticMethods as $method) {
                $staticMethod = $method->getName();
                if (preg_match('/^' . preg_quote($staticMethod, '/') . '(.*)$/', $name, $o) > 0) {
                    self::$staticMethod($arguments[0], $o[1]);
                    break;
                }
            }
        }

    }

}