<?php
/*
 *	GitHub & Bitbucket Deployment Sample Script
 *	Originally found at
 *	http://brandonsummers.name/blog/2012/02/10/using-bitbucket-for-automated-deployments/
 *	http://jonathannicol.com/blog/2013/11/19/automated-git-deployments-from-bitbucket/
 *  
 *	You must 1st 'git clone --mirror' to your local repo directory,
 *	And then, 'GIT_WORK_TREE=[www directory] git checkout -f [your desired branch]'  
 *  
 *	Check `dev-server` branch for the repo which you did simple `git clone`
 *  
  */

/*
 *    Webhook Sample
 *        For BitBucket, use 'POST HOOK'
 *        For GitHub, use 'Webhooks'
 *    URL Format
 *        https://[Basic Auth ID]:[Basic Auth Pass]@example.com/deployments.php?key=EnterYourSecretKeyHere
 *        (https access & Basic Auth makes it a bit more secure if your server supports it)
 */

/**
* The TimeZone format used for logging.
* @var Timezone
* @link    http://php.net/manual/en/timezones.php
*/
date_default_timezone_set('Asia/Tokyo');

/**
* The Secret Key so that it's a bit more secure to run this script
* 
* @var string
*/
$secret_key = 'EnterYourSecretKeyHere';  // Enter the secret key, this works like a password

/**
* The Options
* Only 'directory' is required.
* @var array
*/
$options = array(
    'directory'     => '/path/to/git/repo', // Enter your server's git repo location
    'work_dir'      => '/path/to/www',  // Enter your server's work directory. If you don't separate git and work directory, please leave it empty.
    'log'           => 'deploy_log_filename.log', // relative or absolute path where you save log file.
    'branch'        => 'master', // Indicate which branch you want to checkout
    'remote'        => 'origin', // Indicate which remote repo you want to fetch
    'date_format'   => 'Y-m-d H:i:sP',  // Indicate date format of your log file
    'syncSubmodule' => false, // Currently, this option is no longer working. Lost in some commit.
    'reset'         => false, // If you want to git reset --hard every time you deploy, please set it true
    'git_bin_path'  => 'git',
);

if ($_GET['key'] === $secret_key)  {
    $deploy = new Deploy($options);
	$deploy->execute();
    /*
    $deploy->post_deploy = function() use ($deploy) {
      // hit the wp-admin page to update any db changes
       exec('curl http://example.com/wp-admin/upgrade.php?step=upgrade_db');
       $deploy->log('Updating wordpress database... ');
    };
    */
}

class Deploy {

    /**
    * A callback function to call after the deploy has finished.
    * 
    * @var callback
    */
    public $post_deploy;
    
    /**
    * The name of the file that will be used for logging deployments. Set to 
    * FALSE to disable logging.
    * 
    * @var string
    */
    private $_log = 'deploy.log';
    
    /**
    * The timestamp format used for logging.
    * 
    * @link    http://www.php.net/manual/en/function.date.php
    * @var     string
    */
    private $_date_format = 'Y-m-d H:i:sP';
    
    /**
    * The path to git
    * 
    * @var string
    */
    private $_git_bin_path = 'git';

    /**
    * The directory where your git repository is located, can be 
    * a relative or absolute path from this PHP script on server.
    * 
    * @var string
    */
    private $_directory;

    /**
    * The directory where your git work directory is located, can be 
    * a relative or absolute path from this PHP script on server.
    * 
    * @var string
    */
    private $_work_dir;

    /**
     * Determine if it will execute to git checkout to work directory,
     * or git pull.
     *
     * @var boolean
     */
    private $_topull = false;

    /**
    * Sets up defaults.
    * 
    * @param  array   $option       Information about the deployment
    */
    public function __construct($options = array())
    {

        $available_options = array('directory', 'work_dir', 'log', 'date_format', 'branch', 'remote', 'syncSubmodule', 'reset', 'git_bin_path');
    
        foreach ($options as $option => $value){
            if (in_array($option, $available_options)) {
                $this->{'_'.$option} = $value;
                if ($option == 'directory' || ($option == 'work_dir' && !empty($value)) {
                    // Determine the directory path
                    $this->{'_'.$option} = realpath($value).DIRECTORY_SEPARATOR;
                }
            }
        }

        $this->_topull = false;
        if (empty($this->_work_dir) || ($this->_work_dir == $this->_directory)) {
            $this->_work_dir = $this->_directory;
            $this->_directory = $this->_directory . '.git';
            $this->_topull = true;
        }
    
        $this->log('Attempting deployment...');
        $this->log('Git Directory:' . $this->_directory);
        $this->log('Work Directory:' . $this->_work_dir);
    }

    /**
    * Writes a message to the log file.
    * 
    * @param  string  $message  The message to write
    * @param  string  $type     The type of log message (e.g. INFO, DEBUG, ERROR, etc.)
    */
    public function log($message, $type = 'INFO')
    {
        if ($this->_log) {
            // Set the name of the log file
            $filename = $this->_log;

            if ( ! file_exists($filename)) {
                // Create the log file
                file_put_contents($filename, '');

                // Allow anyone to write to log files
                chmod($filename, 0666);
            }

            // Write the message into the log file
            // Format: time --- type: message
            file_put_contents($filename, date($this->_date_format).' --- '.$type.': '.$message.PHP_EOL, FILE_APPEND);
        }
    }

    /**
    * Executes the necessary commands to deploy the website.
    */
    public function execute()
    {
        try {
            // Git Submodule - Measure the execution time
            $strtedAt = microtime(true);

            // Discard any changes to tracked files since our last deploy
            if ($this->_reset) {
                exec($this->_git_bin_path . ' --git-dir=' . $this->_directory . ' --work-tree=' . $this->_work_dir . ' reset --hard HEAD 2>&1', $output);
                if (is_array($output)) {
                    $output = implode(' ', $output);
                }
                $this->log('Reseting repository... '.$output);
            }

            // Update the local repository
            exec($this->_git_bin_path . ' --git-dir=' . $this->_directory . ' --work-tree=' . $this->_work_dir . ' fetch', $output, $return_var);
            if ($return_var === 0) {
                $this->log('Fetching changes... '.implode(' ', $output));
            } else {
                throw new Exception(implode(' ', $output));
            }

            // Checking out to web directory
            if ($this->_topull) {
                exec('cd ' . $this->_directory . ' && GIT_WORK_TREE=' . $this->_work_dir . ' ' . $this->_git_bin_path . ' pull 2>&1', $output, $return_var);
                if ($return_var === 0) {
                    $this->log('Pulling changes to directory... ' . implode(' ', $output));
                } else {
                    throw new Exception(implode(' ', $output));
                }
            } else {
                exec('cd ' . $this->_directory . ' && GIT_WORK_TREE=' . $this->_work_dir . ' ' . $this->_git_bin_path . ' checkout -f', $output, $return_var);
                if ($return_var === 0) {
                    $this->log('Checking out changes to www directory... ' . implode(' ', $output));
                } else {
                    throw new Exception(implode(' ', $output));
                }

            }

            if ($this->_syncSubmodule) {
                // Wait 2 seconds if main git pull takes less than 2 seconds.
                $endedAt = microtime(true);
                $mDuration = $endedAt - $strtedAt;
                if ($mDuration < 2) {
                    $this->log('Waiting for 2 seconds to execute git submodule update.');
                    sleep(2);
                }
                // Update the submodule
                $output = '';
                exec($this->_git_bin_path . ' --git-dir=' . $this->_directory . ' --work-tree=' . $this->_work_dir . ' submodule update --init --recursive --remote', $output);
                if (is_array($output)) {
                    $output = implode(' ', $output);
                }
                $this->log('Updating submodules...'.$output);
            }

            if (is_callable($this->post_deploy)) {
                call_user_func($this->post_deploy, $this->_data);
            }

            $this->log('Deployment successful.');
        } catch (Exception $e) {
            $this->log($e, 'ERROR');
        }
    }
}
