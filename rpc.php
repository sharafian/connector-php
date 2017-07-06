<?php
  function debug ($msg) {
    file_put_contents('php://stderr', print_r("$msg\n", TRUE));
  }

  function rpc ($uri, $prefix, $method, $data) {
    $ch = curl_init("$uri?prefix=$prefix&method=$method");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    $resp = trim(curl_exec($ch));
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    debug("got code $httpcode, result '$resp'");

    return $resp;
  }

  function getNextHop ($destination) {
    $contents = file('./routing.txt');

    foreach ($contents as $line) {
      debug("check $destination against: $line");
      $route = explode(' ', trim($line));
      $rlen = strlen($route[0]);

      if (substr($destination, 0, $rlen) === $route[0]) {
        return $route;
      }
    }

    return array('error', 'error', 'error');
  }

  function parsePacket ($packet) {
    $decoded = base64_decode($packet);
    $len = ord($decoded[12]);
    return substr($decoded, 13, $len);
  }

  function parseQuoteRequest ($quote) {
    $decoded = base64_decode($quote);
    $len = ord($decoded[2]);
    return substr($decoded, 3, $len);
  }

  function writeNoteToSelf ($obj, $prefix) {
    $line = "{$obj->id} {$prefix}";
    file_put_contents('./notes_to_self.txt', print_r("$line\n", TRUE));
  }

  function readNoteToSelf ($id) {
    $notes = file('./notes_to_self.txt');
    $peers = file('./peers.txt');
    $previous = '';

    foreach ($notes as $line) {
      $transfer = explode(' ', trim($line));
      debug("compare {$transfer[0]} to $id");
      if ($transfer[0] === $id) {
        $previous = $transfer[1];
        break;
      }
    }

    foreach ($peers as $line) {
      $peer = explode(' ', trim($line));
      debug("compare {$peer[0]} to $previous");
      if ($peer[0] === $previous) {
        return $peer;
      }
    }
  }

  function getDestTransfer ($obj, $next) {
    $destTransfer = new stdClass();

    $destTransfer->id = $obj->id;
    $destTransfer->amount = $obj->amount;
    $destTransfer->to = $next[1] . 'server';
    $destTransfer->ledger = $next[1];
    $destTransfer->from = $next[1] . 'client';
    $destTransfer->ilp = $obj->ilp;
    $destTransfer->executionCondition = $obj->executionCondition;
    $destTransfer->expiresAt = $obj->expiresAt;

    return '[' . json_encode($destTransfer) . ']';
  }
  
  $prefix = $_GET["prefix"];
  $method = $_GET["method"];
  $json = file_get_contents("php://input");
  $obj = json_decode($json); 

  if ($method == "send_transfer") {
    $destination = parsePacket($obj[0]->ilp);
    $amount = $obj[0]->amount;
    $next = getNextHop($destination);
    $destTransfer = getDestTransfer($obj[0], $next);

    $uri = $next[2];
    debug("sending $amount to $destination via '$uri'");
    writeNoteToSelf($obj[0], $prefix);

    rpc($uri, $next[1], 'send_transfer', $destTransfer);
    echo "true\n";

  } else if ($method == "fulfill_condition") {
    $id = $obj[0];
    $fulfillment = $obj[1];
    $peer = readNoteToSelf($id);

    debug("$id: fulfilled on $prefix, fulfilling on {$peer[0]}");
    rpc($peer[1], $peer[0], 'fulfill_condition', json_encode($obj));
    echo "true\n";

  } else if ($method == "send_request") {
    $destination = parseQuoteRequest($obj[0]->ilp);
    $next = getNextHop($destination);

    debug("forwarding request for $destination to {$next[1]} via {$next[2]}");
    $result = rpc($next[2], $next[1], 'send_request', json_encode($obj));
    echo "$result\n";
  }
?>
