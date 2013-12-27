
<?php
/* PHP script to bulk import issues in json format from a file to a GitHub repository
 * 
 * The json in the uploaded file should contain an array of issues at the top level. 
 * Fields in the json mapped to the issue title and body (nothing else is supported) 
 * are specified in the submission form.
 * 
 * Depends on the php-github-api from here:  https://github.com/ornicar/php-github-api
 */
session_start();
set_time_limit(600);
require_once(__DIR__ . '/github-php-client/client/GitHubClient.php');

//Helper function to get a value from post or session
function valueInPostOrSession($key, $default = NULL)
{
    if ($_POST[$key]) return $_POST[$key];
    if ($_SESSION[$key]) return $_SESSION[$key];
    return $default;
}

// Pull some values from POST/SESSION early so the form is populated if at all possible
$username = valueInPostOrSession('username');
$password = valueInPostOrSession('password');
$repoowner = valueInPostOrSession('repoowner');
$reponame = valueInPostOrSession('reponame');


// If no action don't do any of this stuff
$action = $_POST['action'];
if ($action)
{    
    $client = new GitHubClient();
    

    // Authenticate with GitHub
    $result = NULL;
    if ($username && $password)
    {
        $client->setCredentials($username,$password);
        $authenticated = 1;
        $_SESSION['username'] = $username;
        $_SESSION['password'] = $password;
    }

    // Perform whatever action was requested (just import for now)
    if ($authenticated)
    {
        switch ($action)
        {
            case "importissues":
            {
                // Grab the issues file and json decode it
                $issuesfilename = $_FILES['issuesfilename']['name'];
                $issuesjson = file($_FILES['issuesfilename']['tmp_name']);
                foreach ($issuesjson as $key => $value) {
                    $state = '';
                    $label = array();
                    $part = explode(",", $value, 2);
                    $data = json_decode(trim($part[1]));
                    switch ($part[0]) {
                        case 'milestones:fields':
                            $milestones_header = array_flip($data);
                            break;
                        case 'milestones':
                            if ( $data[$milestones_header['is_completed']] ) $state = 'closed';
                            else $state = 'open';
                            $response = $client->issues->milestones->createMilestone($username,$reponame,$data[$milestones_header['title']],$data[$milestones_header['description']],$state,$data[$milestones_header['due_date']]."T00:00:00Z");
                            $milestones[$data[$milestones_header['id']]] = $response->getNumber();
                            break;                              
                        case 'ticket_statuses:fields':
                            $state_header = array_flip($data);
                            break;
                        case 'ticket_statuses':
                            $statuses[$data[$state_header['id']]] = $data;
                            break;                            
                        case 'tickets:fields':
                            $ticket_header = array_flip($data);
                            break;
                        case 'tickets':
                            switch ($data[$ticket_header['state']]) {
                                case 0: // Fixed
                                    // Check the status of clousure
                                    switch ($statuses[$data[$ticket_header['ticket_status_id']]][$state_header['name']]) {
                                        case 'Invalid':
                                            $label[] = 'invalid';
                                            break;
                                        case 'Duplicated':
                                            $label[] = 'duplicate';
                                            break;                                        
                                        default:
                                            break;
                                    }
                                    $state = 'closed';
                                    break;
                                case 1: // New or open
                                    $state = 'open';
                                    break;                                
                                default:
                                    break;
                            }
                            //try {
                                $response = $client->issues->createAnIssue($username,$reponame,$data[$ticket_header['summary']],$data[$ticket_header['description']],null,$milestones[$data[$ticket_header['milestone_id']]],$label);
                                $issues[$data[$ticket_header['id']]] = $response->getNumber();
                                $response = $client->issues->editAnIssue($username,$reponame,$data[$ticket_header['summary']],$issues[$data[$ticket_header['id']]],null,null,$state);

                                // Edit an issue to close
                            //} catch ( GitHubClientException $ex) {
                                //echo $ex->getTraceAsString();
                            //}
                            break;      
                            case 'ticket_comments:fields':
                                $ticket_comments_header = array_flip($data);
                                break;
                            case 'ticket_comments':
                                if ( $data[$ticket_comments_header['comment']] != "" ) {
                                    $response = $client->issues->comments->createComment($username,$reponame,$issues[$data[$ticket_comments_header['ticket_id']]],$data[$ticket_comments_header['comment']]);
                                }
                                break;
                                                
                        default:
                            # code...
                            break;
                    }
                }   
            }
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <title></title>
    </head>
    <body>
        <?php
        if ($result)
        {
        ?>
            <div id="result">
                <i><?php echo($result);?></i></br>
            </div>
        <?php
        }
        ?>
        <div id="importissues">
            <form  enctype="multipart/form-data" action="<?php echo $_SERVER['PHP_SELF']; ?>?XDEBUG_SESSION_START=netbeans-xdebug" method="post">
                <b>Github Login</b><br/>
                Username: <input name="username" value="<?php echo($username)?>"/><br/>
                Password: <input type="password" name="password" value="<?php echo($password)?>"/><br/>
                <br>
                <b>Import Issues</b><br/>
                Repository owner (username): <input name="repoowner" value="<?php echo($repoowner)?>"/><br/>
                Repository name: <input name="reponame" value="<?php echo($reponame)?>"/><br/>
                <!-- Issues (json): <input name="issuesjson" value="<?/*echo($issuesjson)*/?>"/><br/> -->
                <input type="hidden" name="MAX_FILE_SIZE" value="1000000" />
                Issues file (json): <input name="issuesfilename" type="file" value="<?php echo($issuestitlefield)?>"/><br/>
                <input type="hidden" name="action" value="importissues"/>
                <input type="submit" value="Submit"/>
            </form>
        </div>
    </body>
</html>