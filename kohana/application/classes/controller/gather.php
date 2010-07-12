<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Gather extends Controller {
    
    public function before($project_id = 0)
    {
        parent::before();
        
        $this->model_gather = new Model_Gather;
        $this->model_params = new Model_Params;
        
        // Config settings (get these from Kohana config file...?)
        $this->connection_retries = 5;
        $this->wait_before_retry = 4; // in seconds
        $this->cron_file = Kohana::config('myconf.path.base')."/project_aware.cron";
    }
    
    public function action_index($gather_from = "")
    {
        if($gather_from == "") {
            print "Gather interval or project ID not defined, cannot continue.\n";
        } elseif($gather_from > 0) {
            // Gather data for specific project
            $this->gather_twitter($gather_from);
        } else {
            $active_projects = $this->model_gather->get_active_projects($gather_from);
            if(count($active_projects) > 0) {
                foreach($active_projects as $project) {
                    $this->gather_twitter($project['project_id']);
                }
            }
        }
    }
    
    /* DEBUG: 
    public function action_crontab()
    {   
        $system("crontab -r", $return_code);
        if($return_code != 0) {
            echo "Error when running command &lt;crontab -r&gt;: $return_code<br>";
        } 
        system("crontab ".$this->cron_file, $return_code);
        if($return_code != 0) {
            echo "Error when running command &lt;crontab ".$this->cron_file."&gt;: $return_code<br>";
        }        
        echo "Result from running command &lt;crontab -l&gt;:<br>";
        system("crontab -l");
    }*/
    
    private function get_project_data($project_id = 0)
    {
        $project_data = $this->model_params->get_project_data($project_id);
        if(count($project_data) > 0) {
            $this->project_data = array_pop($project_data);
            $this->project_id = $this->project_data['project_id'];
            
            $this->keywords_phrases = $this->model_params->get_active_keywords($this->project_id); // Returns multidimensional array of data for each keyword/phrase
        } else {
            $this->project_data = '';
        } 
    }
    
    private function gather_twitter($project_id = 0)
    {
        // Twitter Search API parameters:
        $api_id = 1; // api_id = 1 for Twitter Search API  
        $api_url = "http://search.twitter.com/search.json"; // LATER: Get this from database
        $results_per_page = "&rpp=100"; // Max is 100
        $lang = ""; //"&lang=en"; // Limit search to English tweets...DOESN'T SEEM TO BE WORKING RIGHT
        
        // TO DO: move this \/
        $this->get_project_data($project_id); // Move this to main 'gather' method which determines all APIs to gather from then executes method for each
        
        if(!$this->project_data) {
            print "Project with this ID does not exist.";
        } else {
        
        // Add keywords/phrases to query string 
        $keyword_str = "";
        $num_keywords = count($this->keywords_phrases);
        $i = 0;
        foreach($this->keywords_phrases as $keyword_phrase) {
            $i++;
            if(str_word_count($keyword_phrase['keyword_phrase']) > 1) { // Is phrase (more than 1 word)
                
                // Check if searching "exact phrase" -> add quotes
                    $keyword_str .= '"'.urlencode($keyword_phrase['keyword_phrase']).'"';
                // else
                    //$keyword_str .= '('.urlencode($keyword_phrase['keyword_phrase']).')';
                    
            } else { // Is single keyword
                $keyword_str .= urlencode($keyword_phrase['keyword_phrase']);
            }
            if($i < $num_keywords) {
                $keyword_str .= '+OR+';
            }
        }
        
        //
        // TO DO: Add negative keywords to query string
        // +-negkey1+-negkey2
        // 
        
        /* TWITTER Streaming API
            $request_url = "http://paradoxic7:zircon#107@stream.twitter.com/1/statuses/sample.json";
            $response = Remote::get($request_url, array(
                CURLOPT_POST => TRUE,
            ));*/
        
        $cur_page = 1;
        while(TRUE) {
            // Compile request URL
            $request_url = $api_url.'?q='.$keyword_str.$lang.$results_per_page.'&page='.$cur_page;
            print "Query: $request_url\n";
            
            // Connect to API & check for errors
            $num_requests_sent = 0;
            while(TRUE) {
                if($num_requests_sent > $this->connection_retries) {
                    //
                    // TO DO: Send email notification
                    // ...
                    // 
                    print "Could not connect to API with request: $request_url\n";
                    exit;
                } else {
                    if($num_requests_sent > 0)
                        print "Re-trying ($num_requests_sent)...\n";
                    
                    // Try connecting to API
                    $response = Remote::get($request_url, array(
                        CURLOPT_RETURNTRANSFER => TRUE
                        //CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT']
                    ));
                    if(substr($response, 0, 5) == "ERROR") {
                        $error_code = preg_replace('/[^0-9]/', '', $response); // Remove all non-numeric chars
                        print "ERROR: $error_code\n"; 
                        $num_requests_sent++;
                        sleep($this->wait_before_retry); // Wait before trying to reconnect
                    } else {
                        print "Successfully connected to Twitter Search API!\n";
                        break; // Connection successful
                    }
                }
            }
            
            // Loop through each tweet (if there are results on this page)
            $json = json_decode($response, true);
            $num_results = count($json['results']);
            
            if($num_results > 0) {
                
                foreach($json['results'] as $tweet_data) {
                    //DEBUG:
                    //print_r($tweet_data);
                    $date_published = $tweet_data['created_at']; 
                    $date_published_timestamp = strtotime($date_published); // $this->date_to_timestamp($date_published);
                    $username = $tweet_data['from_user'];
                    $tweet_id = $tweet_data['id'];
                    $tweet_url = "http://twitter.com/$username/status/$tweet_id";
                    $tweet_text = $tweet_data['text'];
                    $tweet_lang = $tweet_data['iso_language_code'];
                    // Geolocation info
                    $place = "";
                    if(array_key_exists('place', $tweet_data)) {
                        foreach($tweet_data['place'] as $place_data) {
                            $place .= "$place_data, ";
                        }
                    }
                    $geolocation = $tweet_data['geo'];
                    
                    // Find total number of words in given tweet (remove URLs & special chars first because they off-set word count)
                    $filtered_text = preg_replace('/\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|$!:,.;]*[A-Z0-9+&@#\/%=~_|$]/i', '', $tweet_text); // Remove URLs
                    $filtered_text = preg_replace('/[^A-Za-z0-9\s]/', '', $filtered_text); // Remove all non-alpha_numeric chars
                    $total_words = str_word_count($filtered_text);
                    
                    //DEBUG:
                    //echo "<p>$username; $date_published (".date('D, d M Y H:i:s O', $date_published_timestamp).") $tweet_id; $place; $geolocation;<br>$tweet_text;</p>";
                    
                    /* TO DO:
                     * Put all of this into a method (for code reused w/ each API) 
                     * EX: add_metadata($cache_text, Array $metadata)
                     * 
                     * MAYBE: verify $tweet_text has at least 1 keyword phrase
                     */ 
                    
                    // Check if URL has already been entered into database
                    if($this->model_gather->url_exists($this->project_id, $tweet_url)) {
                        
                        //DEBUG:
                        print "exists: $tweet_url\n";
                        
                        //
                        // TO DO: Check if this page/URL was updated...if so: add new metadata entry
                        // 
                        
                    } else {
                        //DEBUG:
                        print "added: $tweet_url\n";
                        
                        // Add new metadata entry 
                        $url_id = $this->model_gather->insert_url(array(
                            'project_id' => $this->project_id, 
                            'url' => $tweet_url
                        ));
                        $metadata = array(
                            'project_id' => $this->project_id,
                            'api_id' => $api_id,
                            'date_published' => $date_published_timestamp,
                            'date_retrieved' => time(),
                            'lang' => $tweet_lang,
                            'total_words' => $total_words,
                            'place' => $place,
                            'geolocation' => $geolocation,
                            'url_id' => $url_id
                        );
                        $meta_id = $this->model_gather->insert_metadata($metadata);
                        // Count number of occurences of *each* keyword in tweet (& add keyword entry to database when count > 0)
                        $this->generate_keyword_metadata($tweet_text, $meta_id);
                        $this->model_gather->insert_cached_text(array(
                            'meta_id' => $meta_id,
                            'text' => $tweet_text
                        ));
                    }
                }
                
                $cur_page++;
                
            } else {
                break; // No results on this page so we are DONE!
            }
        }
        
        }
    }
    
    // Counts total number of occurances of each [active] keyword in given $text and adds an keyword entry for each where count > 0
    private function generate_keyword_metadata($text, $meta_id) 
    {
        foreach($this->keywords_phrases as $keyword_phrase) {
            $num_occurances = preg_match_all("/\b(".$keyword_phrase['keyword_phrase'].")\b/ie", $text, $matches);
            if($num_occurances > 0) {
                // Add database entry for given keyword
                $keyword_metadata = array(
                    'meta_id' => $meta_id,
                    'keyword_id' => $keyword_phrase['keyword_id'],
                    'num_occurrences' => $num_occurances
                );
                $this->model_gather->insert_keyword_metadata($keyword_metadata);
            }
        }
    }
    
    private function date_to_timestamp($date_str) 
    { 
            list($D, $d, $M, $y, $h, $m, $s, $z) = sscanf($date_str, "%3s, %2d %3s %4d %2d:%2d:%2d %5s"); 
            return strtotime("$d $M $y $h:$m:$s $z");
    } 
    
    public function action_test($var = 0)
    {
        //EX COMMAND: php /path/to/kohana/index.php --uri=gather/test --user=test1 --pass=test2
        
        // Get the values of `user` and `pass`
        //$params = CLI::options('user', 'pass'); // $params['user'] and $params['pass']
        print "Var: $var\n";
        //mkdir("/home/adrian/Documents/GSoC_2010/src/".$params['user']);
    }
}