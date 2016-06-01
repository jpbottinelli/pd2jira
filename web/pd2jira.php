<?php
$messages = json_decode(file_get_contents("php://input"));

$jira_url = getenv('JIRA_URL');
$jira_username = getenv('JIRA_USERNAME');
$jira_password = getenv('JIRA_PASSWORD');
$jira_issue_type = getenv('JIRA_ISSUE_TYPE');
$pd_subdomain = getenv('PAGERDUTY_SUBDOMAIN');
$pd_api_token = getenv('PAGERDUTY_API_TOKEN');

if ($messages) foreach ($messages->messages as $webhook) {
  $webhook_type = $webhook->type;

  switch ($webhook_type) {
    case "incident.trigger":
      if(file_exists('lock.txt') && file_get_contents('lock.txt') > (time() - 5)){
        die('Should not run!');
      }
      file_put_contents('lock.txt', time());
      $incident_id = $webhook->data->incident->id;
      $incident_number = $webhook->data->incident->incident_number;
      $ticket_url = $webhook->data->incident->html_url;
      $pd_requester_id = $webhook->data->incident->assigned_to_user->id;
      $service_name = $webhook->data->incident->service->name;
      $service_description = $webhook->data->incident->service->description;
      $assignee = $webhook->data->incident->assigned_to_user->name;
      $assignee_email = $webhook->data->incident->assigned_to_user->email;
      $urgency = strtoupper($webhook->data->incident->urgency);
      $incident_key = $webhook->data->incident->incident_key;
      $trigger_summary_description = $webhook->data->incident->trigger_summary_data->description;
      //Default JIRA Priority id set to "Not prioritized"
      $priority_id = 10000;
      //Default JIRA Project
      $jira_project = "STEMP";

      if (strcmp($urgency, "HIGH") == 0) {
        $priority_id = 2;
      }
      elseif (strcmp($urgency, "LOW") == 0) {
        $priority_id = 4;
      }
     
     /*Separates the email user name from the domain 
     * so we have the username to be assigned on the JIRA ticket
     */
      $address = explode("@", $assignee_email);
      
      if ($webhook->data->incident->trigger_summary_data->subject) {
        $trigger_summary_data = $webhook->data->incident->trigger_summary_data->subject;
      }
      else {
        $trigger_summary_data = $trigger_summary_description;
      }

      

      $summary = "$trigger_summary_data";

      $verb = "triggered";
      
      preg_match_all("/^JIRA PROJECT KEY: (.*)$/im", $service_description, $jira_project_key_match);

      $jira_project_key = $jira_project_key_match[1][0];
      if(!empty($jira_project_key)) { 
        $jira_project = trim($jira_project_key);
      }

      //If the escalation is for Zendesk tickets, build the url
      if (strpos(strtoupper($service_name), strtoupper('ZENDESK')) !== false) {
        $zendesk_url = "https://medallia.zendesk.com/hc/requests/$incident_key";
      }

      //Let's make sure the note wasn't already added (Prevents a 2nd Jira ticket in the event the first request takes long enough to not succeed according to PagerDuty)
      $notes_url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
      $return = http_request($notes_url, "", "GET", "token", "", $pd_api_token);
      if ($return['status_code'] == '200') {
        $response = json_decode($return['response'], true);
        if (array_key_exists("notes", $response)) {
          foreach ($response['notes'] as $value) {
            $startsWith = "JIRA ticket";
            if (substr($value['content'], 0, strlen($startsWith)) === $startsWith) {
              /*//If the ticket already exists in JIRA, we create a comment to show repetition
              date_default_timezone_set('America/Los_Angeles');
              preg_match_all("/^JIRA ticket (.*) has been created/im", $value['content'], $jira_key_to_comment_match);
              $jira_key_to_comment = trim($jira_key_to_comment_match[1][0]);
              if(!empty($jira_key_to_comment)) { 
                $update_date =  date('m/d/Y H:i:s');
                $comment_url = "$jira_url/rest/api/2/issue/$jira_key_to_comment/comment";
                $data_comment = array('body'=>"This incident was triggered again on $update_date. Please confirm that it's not a high sev issue");
                $data_comment_json = json_encode($data_comment);
                $return_comment = http_request($url, $data_comment_json, "POST", "basic", $jira_username, $jira_password);
                
                $status_code_comment = $return_comment['status_code'];
                $response_comment = $return_comment['response'];
                $response_obj = json_decode($response_comment);
                $response_key = $response_obj->key;

                if ($status_code_comment == "201") {
                  //Update the PagerDuty ticket with the JIRA comment.
                  $url_note = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
                  $data_note = array('note'=>array('content'=>"JIRA ticket updated with latest incident repetition."),'requester_id'=>"$pd_requester_id");
                  $data_note_json = json_encode($data_note);
                  http_request($url_note, $data_note_json, "POST", "token", "", $pd_api_token);
                }
                else {
                  //Update the PagerDuty ticket if the JIRA comment isn't made.
                  error_log("Couldn't comment on ticket $jira_key_to_comment. PD incident: $incident_id");
                  $url_note = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
                  $data_note = array('note'=>array('content'=>"Couldn't update ticket with comment on repetition. $response"),'requester_id'=>"$pd_requester_id");
                  $data_note_json = json_encode($data_note);
                  http_request($url_note, $data_note_json, "POST", "token", "", $pd_api_token);
                }
              }
              else {
                error_log("JIRA ticket to comment not found");  
              }*/
              break 2; //Skip it cause it would be a duplicate
            }
          }
        }
      }

      //Create the JIRA ticket when an incident has been triggered
      $issue_url = "$jira_url/rest/api/2/issue/";

      $data = array('fields'=>array('project'=>array('key'=>"$jira_project"),'summary'=>"$summary",
          'description'=>"$trigger_summary_description\r\nKey: $incident_key",
          'issuetype'=>array('name'=>"$jira_issue_type"), 'assignee'=>array('name'=>"$address[0]"),
          'priority'=>array('id'=>"$priority_id"), 'customfield_12500'=>"$ticket_url",
          'customfield_10227'=>"$zendesk_url"));
      
      $data_json = json_encode($data);
      $return = http_request($issue_url, $data_json, "POST", "basic", $jira_username, $jira_password);
      $status_code = $return['status_code'];
      $response = $return['response'];
      $response_obj = json_decode($response);
      $response_key = $response_obj->key;

      if ($status_code == "201") {
        //Update the PagerDuty ticket with the JIRA ticket information.
        $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
        $data = array('note'=>array('content'=>"JIRA ticket $response_key has been created.  You can view it at $jira_url/browse/$response_key."),'requester_id'=>"$pd_requester_id");
        $data_json = json_encode($data);
        http_request($url, $data_json, "POST", "token", "", $pd_api_token);
      }
      else {
        //Update the PagerDuty ticket if the JIRA ticket isn't made.
        $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
        $data = array('note'=>array('content'=>"A JIRA ticket failed to be created. $response"),'requester_id'=>"$pd_requester_id");
        $data_json = json_encode($data);
        http_request($url, $data_json, "POST", "token", "", $pd_api_token);
      }
      break;
    default:
      continue;
  }
}

function http_request($url, $data_json, $method, $auth_type, $username, $token) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  if ($auth_type == "token") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json),"Authorization: Token token=$token"));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
  }
  else if ($auth_type == "basic") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json)));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$token");
  }
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  if ($data_json != "") {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
  }
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response  = curl_exec($ch);
  $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if(curl_errno($ch)){
    error_log('Curl error: ' . curl_error($ch));
  }
  curl_close($ch);
  return array('status_code'=>"$status_code",'response'=>"$response");
}
?>
