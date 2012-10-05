<?php

/*
 * Git.php
 *
 * A PHP git library
 *
 * @package    Git.php
 * @version    0.1.1-a
 * @author     James Brumond
 * @copyright  Copyright 2010 James Brumond
 * @license    http://github.com/kbjr/Git.php
 * @link       http://code.kbjrweb.com/project/gitphp
 */

if (__FILE__ == $_SERVER['SCRIPT_FILENAME']) die('Bad load order');

// ------------------------------------------------------------------------

/**
 * Git Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of git repositories.
 *
 * @class  Git
 */
class Git {

	/**
	 * Create a new git repository
	 *
	 * Accepts a creation path, and, optionally, a source path
	 *
	 * @access  public
	 * @param   string  repository path
	 * @param   string  directory to source
	 * @param   bool    is the repo a bare one?
     * @param   string  the 'shared' option as per git init - (false|true|umask|group|all|world|everybody|0xxx)
	 * @return  GitRepo
	 */	
	public static function &create($repo_path, $source = null, $bare = false, $shared = false) {
		return GitRepo::create_new($repo_path, $source, $bare, $shared);
	}

	/**
	 * Open an existing git repository
	 *
	 * Accepts a repository path
	 *
	 * @access  public
	 * @param   string  repository path
	 * @return  GitRepo
	 */	
	public static function open($repo_path) {
		return new GitRepo($repo_path);
	}

	/**
	 * Checks if a variable is an instance of GitRepo
	 *
	 * Accepts a variable
	 *
	 * @access  public
	 * @param   mixed   variable
	 * @return  bool
	 */	
	public static function is_repo($var) {
		return (get_class($var) == 'GitRepo');
	}
	
}

// ------------------------------------------------------------------------

/**
 * Git Repository Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of a git repository
 *
 * @class  GitRepo
 */
class GitRepo {

	protected $repo_path = null;
	
	public $git_path = '/usr/bin/git';

	/**
	 * Create a new git repository
	 *
	 * Accepts a creation path, and, optionally, a source path
	 *
	 * @access  public
	 * @param   string  repository path
	 * @param   string  directory to source
 	 * @param   bool    is the repo a bare one?
     * @param   string  the 'shared' option as per git init - (false|true|umask|group|all|world|everybody|0xxx)
	 * @return  GitRepo
	 */	
	public static function &create_new($repo_path, $source = null, $bare = false, $shared = false) {

		if (is_dir($repo_path) && ((file_exists($repo_path."/.git") && is_dir($repo_path."/.git")) || (file_exists($repo_path."/HEAD") && is_dir($repo_path."/objects")))) {
			throw new Exception("'$repo_path' is already a git repository");
		} else {
            $shared = strtolower(trim($shared));

            // Sanity check the shared option
            if(!preg_match('/^(true)|(umask)|(group)|(all)|(world)|(everybody)|(0\d{3})$/', $shared))
                $shared = false;
            

			$repo = new self($repo_path, true, false, $bare);
			if (is_string($source))
				$repo->clone_from($source);
			else
                $args = '';
                if($bare)
                    $args .= ' --bare';

                if($shared)
                    $args .= " --shared=$shared";

				$repo->run("init $args");
			return $repo;
		}
	}

	/**
	 * Constructor
	 *
	 * Accepts a repository path
	 *
	 * @access  public
	 * @param   string  repository path
	 * @param   bool    create if not exists?
 	 * @param   bool    is the repo a bare one?
	 * @return  void
	 */
	public function __construct($repo_path = null, $create_new = false, $_init = true, $bare = false) {
		if (is_string($repo_path))
			$this->set_repo_path($repo_path, $create_new, $_init, $bare);
	}

	/**
	 * Set the repository's path
	 *
	 * Accepts the repository path
	 *
	 * @access  public
	 * @param   string  repository path
	 * @param   bool    create if not exists?
 	 * @param   bool    is the repo a bare one?
	 * @return  void
	 */
	public function set_repo_path($repo_path, $create_new = false, $_init = true, $bare = false) {

		// Sanity check the path first...
		if(!is_string($repo_path)){
			throw new Exception("'$repo_path' is not a valid repository path");
		}

		// Expand symlinks etc. to give an absolute path
		$repo_path = realpath($repo_path);

		// Get the parent directory, if possible
		$parent = realpath(dirname($repo_path));

		// It's a git repository if it contains a .git directory,
		// or it's a bare repo with a HEAD file and objects dir.
		$is_repo = (
		  is_dir($repo_path) && (
		    is_dir($repo_path."/.git") ||
			(file_exists($repo_path."/HEAD") && is_dir($repo_path."/objects"))
		  )
		);

		// If this is NOT already a repository and we're allowed to create a new one, do so
		if(!$is_repo){
			if($create_new){

				if(!is_dir($parent)){
					throw new Exception("Cannot create repository - parent directory does not exist");
				}

				if(!is_dir($repo_path) && !mkdir($repo_path)){
					throw new Exception("Failed to create repository path");
				}

                $this->repo_path = $repo_path;

				// If we're supposed to init the repo too, try to do that
				if ($_init) {
					if ($bare)
						$this->run('init --bare');
					else
						$this->run('init');
				}

			// ...or bomb out if we're not allowed to.
			} else{
				throw new Exception("Repository path '$repo_path' does not exist");
			}

        // Repository already exists, so just store the path for later use
		} else{
            $this->repo_path = $repo_path;
        }

	}

	/**
	 * Tests if git is installed
	 *
	 * @access  public
	 * @return  bool
	 */	
	public function test_git() {
		$descriptorspec = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$resource = proc_open($this->git_path, $descriptorspec, $pipes);

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		$status = trim(proc_close($resource));
		return ($status != 127);
	}

	/**
	 * Run a command in the git repository
	 *
	 * Accepts a shell command to run
	 *
	 * @access  protected
	 * @param   string  command to run
	 * @return  string
	 */	
	protected function run_command($command) {
		$descriptorspec = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$resource = proc_open($command, $descriptorspec, $pipes, $this->repo_path);

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		$status = trim(proc_close($resource));
		if ($status) throw new Exception($stderr);

		return $stdout;
	}

	/**
	 * Run a git command in the git repository
	 *
	 * Accepts a git command to run
	 *
	 * @access  public
	 * @param   string  command to run
	 * @return  string
	 */	
	public function run($command) {
		return $this->run_command($this->git_path." ".$command);
	}

	/**
	 * Runs a `git add` call
	 *
	 * Accepts a list of files to add
	 *
	 * @access  public
	 * @param   mixed   files to add
	 * @return  string
	 */	
	public function add($files = "*") {
		if (is_array($files)) $files = '"'.implode('" "', $files).'"';
		return $this->run("add $files -v");
	}

	/**
	 * Runs a `git commit` call
	 *
	 * Accepts a commit message string
	 *
	 * @access  public
	 * @param   string  commit message
	 * @return  string
	 */	
	public function commit($message = "") {
		return $this->run("commit -av -m \"$message\"");
	}

	/**
	 * Runs a `git clone` call to clone the current repository
	 * into a different directory
	 *
	 * Accepts a target directory
	 *
	 * @access  public
	 * @param   string  target directory
	 * @return  string
	 */	
	public function clone_to($target) {
		return $this->run("clone --local ".$this->repo_path." $target");
	}

	/**
	 * Runs a `git clone` call to clone a different repository
	 * into the current repository
	 *
	 * Accepts a source directory
	 *
	 * @access  public
	 * @param   string  source directory
	 * @return  string
	 */	
	public function clone_from($source) {
		return $this->run("clone --local $source ".$this->repo_path);
	}

	/**
	 * Runs a `git clone` call to clone a remote repository
	 * into the current repository
	 *
	 * Accepts a source url
	 *
	 * @access  public
	 * @param   string  source url
	 * @return  string
	 */	
	public function clone_remote($source) {
		return $this->run("clone $source ".$this->repo_path);
	}

	/**
	 * Runs a `git clean` call
	 *
	 * Accepts a remove directories flag
	 *
	 * @access  public
	 * @param   bool    delete directories?
	 * @return  string
	 */	
	public function clean($dirs = false) {
		return $this->run("clean".(($dirs) ? " -d" : ""));
	}

	/**
	 * Runs a `git branch` call
	 *
	 * Accepts a name for the branch
	 *
	 * @access  public
	 * @param   string  branch name
	 * @return  string
	 */	
	public function create_branch($branch) {
		return $this->run("branch $branch");
	}

	/**
	 * Runs a `git branch -[d|D]` call
	 *
	 * Accepts a name for the branch
	 *
	 * @access  public
	 * @param   string  branch name
	 * @return  string
	 */	
	public function delete_branch($branch, $force = false) {
		return $this->run("branch ".(($force) ? '-D' : '-d')." $branch");
	}

	/**
	 * Runs a `git branch` call
	 *
	 * @access  public
	 * @param   bool    keep asterisk mark on active branch
	 * @return  array
	 */
	public function list_branches($keep_asterisk = false) {
		$branchArray = explode("\n", $this->run("branch"));
		foreach($branchArray as $i => &$branch) {
			$branch = trim($branch);
			if (! $keep_asterisk)
				$branch = str_replace("* ", "", $branch);
			if ($branch == "")
				unset($branchArray[$i]);
		}
		return $branchArray;
	}

	/**
	 * Returns name of active branch
	 *
	 * @access  public
	 * @param   bool    keep asterisk mark on branch name
	 * @return  string
	 */
	public function active_branch($keep_asterisk = false) {
		$branchArray = $this->list_branches(true);
		$active_branch = preg_grep("/^\*/", $branchArray);
		reset($active_branch);
		if ($keep_asterisk)
			return current($active_branch);
		else
			return str_replace("* ", "", current($active_branch));
	}

	/**
	 * Runs a `git checkout` call
	 *
	 * Accepts a name for the branch
	 *
	 * @access  public
	 * @param   string  branch name
	 * @return  string
	 */	
	public function checkout($branch) {
		return $this->run("checkout $branch");
	}

}

/* End Of File */
