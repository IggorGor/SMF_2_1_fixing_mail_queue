<?php
/**
 * @author Igor Gorbenkov
 * @license GPL
 * @created_at 2024-02-22
 * @last_modified 2024-02-22
 *
 * This script solves the problem of stopping the queue for sending mail
 * GitHub Repository: https://github.com/IggorGor/SMF_2_1_fixing_mail_queue
 */

set_time_limit(0);

require_once 'Settings.php';

global $boardurl;

function getDbConnection(): mysqli
{
    global $db_server, $db_user, $db_passwd, $db_name, $db_port;

    $connect = mysqli_connect($db_server, $db_user, $db_passwd, $db_name, $db_port)
    or die('Cannot connect to Database');

    return $connect;
}

$connect = getDbConnection();

$deleteUsersWithoutPosts = mysqli_prepare(
    $connect,
    "DELETE FROM members WHERE email_address LIKE CONCAT('%', ?) AND posts = 0");

$deleteQueue = mysqli_prepare(
    $connect,
    "DELETE FROM mail_queue WHERE recipient LIKE CONCAT('%', ?)");

$selectUsers = mysqli_prepare(
    $connect,
    "SELECT id_member, member_name, email_address, posts FROM members WHERE email_address LIKE CONCAT('%@', ?)");

function renderDeletedDomains($invalidDomainsMember, $deleteUsersWithoutPosts, $deleteQueue)
{
    $deleteMemberCounter = 0;
    $deleteQueueCounter = 0;
    $n = 0;
    foreach ($invalidDomainsMember as $invalidDomain) {
        $deleteUsersWithoutPosts->bind_param('s', $invalidDomain);
        $deleteUsersWithoutPosts->execute();
        $memberCounter = $deleteUsersWithoutPosts->affected_rows;
        $deleteQueue->bind_param('s', $invalidDomain);
        $deleteQueue->execute();
        $queueCounter = $deleteQueue->affected_rows;
        if ($memberCounter > 0 || $queueCounter > 0) {
            $n++;
            echo "<tr><td>$n</td><td>$invalidDomain</td><td>$memberCounter</td><td>$queueCounter</td></tr>";
            $deleteMemberCounter += $memberCounter;
            $deleteQueueCounter += $queueCounter;
        }
    }
    echo "<tr><td><b>$n</b></td><td><b>Итого:</b></td><td><b>$deleteMemberCounter</b></td><td><b>$deleteQueueCounter</b></td></tr>";
}

function renderFailedDeletes($invalidDomainsMember, $selectUsers, $boardurl) {
    $n = 0;
    foreach ($invalidDomainsMember as $invalidDomain) {
        $selectUsers->bind_param('s', $invalidDomain);
        $selectUsers->execute();
        $id_member = 0;
        $member_name = '';
        $email_address = '';
        $posts = 0;
        $selectUsers->bind_result($id_member, $member_name, $email_address, $posts);
        while ($selectUsers->fetch()) {
            $n++;
            echo "<tr><td>$n</td><td><a href=\"$boardurl/index.php?action=profile;u=$id_member\">$member_name</a></td><td>$email_address</td><td>$posts</td></tr>";
        }
    }
}

function renderDeletedQueues($invalidQueues, $deleteQueue) {
    $n = 0;
    $deleteQueueCounter = 0;
    foreach ($invalidQueues as $invalidDomain) {
        $deleteQueue->bind_param('s', $invalidDomain);
        $deleteQueue->execute();
        $deleteCounters = $deleteQueue->affected_rows;
        if ($deleteCounters > 0) {
            $n++;
            echo "<tr><td>$n</td><td>$invalidDomain</td><td>$deleteCounters</td></tr>";
            $deleteQueueCounter += $deleteCounters;
        }
    }
    echo "<tr><td>$n</td><td>Итого</td><td>$deleteQueueCounter</td></tr>";
}

function getInvalidDomains(mysqli_result $queryResult): array
{
    $invalidDomains = [];
    while ($row = mysqli_fetch_assoc($queryResult)) {
        if (!checkdnsrr($row['domain'])) {
            $invalidDomains[] = $row['domain'];
        }
    }
    return $invalidDomains;
}

function getInvalidDomainsFromMembers(mysqli $connect): array
{
    $sql = "SELECT DISTINCT SUBSTRING_INDEX(m.email_address, '@', -1) AS domain FROM members m";
    $result = mysqli_query($connect, $sql);
    return getInvalidDomains($result);
}

function getInvalidDomainsFromMailQueue(mysqli $connect): array
{
    $sql = "SELECT DISTINCT SUBSTRING_INDEX(m.recipient, '@', -1) AS domain FROM mail_queue m";
    $result = mysqli_query($connect, $sql);
    return getInvalidDomains($result);
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <title>Удаление зловредных посетителей</title>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-auto">
            <table class="table table-striped caption-top">
                <caption class="text-center p-3">Успешно удалённые домены</caption>
                <tr>
                    <th>№</th>
                    <th>Домен</th>
                    <th>Удалено в members</th>
                    <th>Удалено в mail_queue</th>
                </tr>
                <?php
                    $invalidDomainsMember = getInvalidDomainsFromMembers($connect);
                    renderDeletedDomains($invalidDomainsMember, $deleteUsersWithoutPosts, $deleteQueue);
                ?>
            </table>

            <table class="table table-striped caption-top">
                <caption class="text-center p-3">Пользователи, которых удалить не удалось</caption>
                <tr>
                    <th>№</th>
                    <th>Ник</th>
                    <th>Почта</th>
                    <th>Кол-во сообщений</th>
                </tr>
                <?php
                    renderFailedDeletes($invalidDomainsMember, $selectUsers, $boardurl);
                ?>
            </table>

            <table class="table table-striped caption-top">
                <caption class="text-center p-3">Удалено из очереди</caption>
                <tr>
                    <th>№</th>
                    <th>Домен</th>
                    <th>Удалено</th>
                </tr>
                <?php
                    renderDeletedQueues(getInvalidDomainsFromMailQueue($connect), $deleteQueue);
                ?>
            </table>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
</body>
</html>