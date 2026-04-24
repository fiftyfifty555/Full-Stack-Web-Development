<?php
session_start();
require_once 'session_timeout.php';

$conn = new mysqli('localhost', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Ошибка подключения к базе данных']));
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        if ($_SESSION['role'] !== 'admin') {
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            exit();
        }
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        if (mb_strlen($title) > 45 || mb_strlen($content) > 2048) {
            echo json_encode(['success' => false, 'error' => 'Превышена максимальная длина']);
            exit();
        }
        $stmt = $conn->prepare("INSERT INTO news (title, content) VALUES (?, ?)");
        $stmt->bind_param("ss", $title, $content);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка при создании новости']);
        }
        $stmt->close();
        break;

    case 'update':
        if ($_SESSION['role'] !== 'admin') {
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            exit();
        }
        $news_id = intval($_POST['news_id']);
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        if (mb_strlen($title) > 45 || mb_strlen($content) > 2048) {
            echo json_encode(['success' => false, 'error' => 'Превышена максимальная длина']);
            exit();
        }
        $stmt = $conn->prepare("UPDATE news SET title = ?, content = ? WHERE news_id = ?");
        $stmt->bind_param("ssi", $title, $content, $news_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка при обновлении новости']);
        }
        $stmt->close();
        break;

    case 'delete':
        if ($_SESSION['role'] !== 'admin') {
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            exit();
        }
        $news_id = intval($_POST['news_id']);
        $stmt = $conn->prepare("DELETE FROM news WHERE news_id = ?");
        $stmt->bind_param("i", $news_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка при удалении новости']);
        }
        $stmt->close();
        break;

    case 'get':
        $sql = "SELECT news_id, title, content, created_at FROM news ORDER BY created_at DESC";
        $result = $conn->query($sql);
        $news = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $date = new DateTime($row['created_at']);
                $formatter = new IntlDateFormatter('ru_RU', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, "HH:mm d MMMM Y 'года'");
                $formatted_date = $formatter->format($date);
                $fullText = $row['content'];
                $firstLine = trim(strtok($fullText, "\n"));
                if ($firstLine === false || $firstLine == "") {
                    $firstLine = $fullText;
                }
                if (mb_strlen($firstLine, 'UTF-8') > 120) {
                    $excerpt = mb_substr($firstLine, 0, 120, 'UTF-8') . "...";
                } else {
                    $excerpt = $firstLine;
                    if (strpos($fullText, "\n") !== false) {
                        $excerpt .= "...";
                    }
                }
                $news[] = [
                    'news_id' => $row['news_id'],
                    'title' => $row['title'],
                    'content' => $row['content'],
                    'excerpt' => $excerpt,
                    'created_at' => $formatted_date
                ];
            }
        }
        echo json_encode(['success' => true, 'news' => $news]);
        break;
}

$conn->close();
