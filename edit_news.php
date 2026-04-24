<?php
session_start();
require_once 'session_timeout.php';

// Проверка доступа: только администратор имеет доступ
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: news.php");
    exit();
}

$conn = new mysqli('localhost', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Получение id новости из GET-параметра
if (!isset($_GET['id'])) {
    header("Location: news.php");
    exit();
}
$news_id = intval($_GET['id']);

// Извлекаем текущие данные новости из таблицы news
$sql = "SELECT title, content FROM news WHERE news_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $news_id);
$stmt->execute();
$stmt->bind_result($title, $content);
if (!$stmt->fetch()) {
    $stmt->close();
    header("Location: news.php");
    exit();
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать новость</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #6dd5ed, #2193b0);
            color: #fff;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        header {
            background: rgba(0,0,0,0.6);
            padding: 20px 0;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        header h1 {
            margin: 0;
            font-size: 2.5em;
            color: #fff;
        }
        nav {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 15px;
        }
        nav a {
            color: #fff;
            text-decoration: none;
            font-size: 1.2em;
            padding: 10px 20px;
            border: 2px solid #fff;
            border-radius: 25px;
            transition: 0.3s;
        }
        nav a:hover,
        nav a.active {
            background: #fff;
            color: #2193b0;
        }
        .content {
            padding: 50px 20px;
            text-align: center;
            flex: 1;
        }
        .form-container {
            background: rgba(255,255,255,0.9);
            padding: 30px;
            border-radius: 10px;
            margin: 20px auto;
            width: 90%;
            max-width: 800px;
            color: #000;
            position: relative;
        }
        .input-group {
            position: relative;
            margin-bottom: 30px;
        }
        .input-group input,
        .input-group textarea {
            width: 100%;
            padding: 10px;
            font-size: 1em;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }
        .input-group textarea {
            height: 300px;
            resize: vertical;
        }
        .char-counter {
            position: absolute;
            bottom: 5px;
            right: 10px;
            font-size: 0.8em;
            color: #888;
        }
        .form-container button {
            padding: 10px 20px;
            background: #2193b0;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
        }
        .form-container button:hover {
            background: #1a7a8f;
        }
        .error-message {
            color: red;
            font-size: 1em;
            margin-top: 10px;
        }
        footer {
            text-align: center;
            padding: 20px;
            background: rgba(0,0,0,0.8);
            color: #fff;
            margin-top: auto;
        }
    </style>
</head>
<body>
    <header>
        <h1>Редактировать новость</h1>
        <nav>
            <a href="news.php">Назад к новостям</a>
        </nav>
    </header>
    <section class="content">
        <div class="form-container">
            <h2>Редактировать новость</h2>
            <div id="error-message" class="error-message" style="display:none;"></div>
            <form id="edit-news-form">
                <div class="input-group">
                    <label for="title">Заголовок (максимум 45 символов):</label>
                    <input type="text" id="title" name="title" maxlength="45" required value="<?php echo htmlspecialchars($title); ?>">
                    <span class="char-counter" id="counter-title">45 символов осталось</span>
                </div>
                <div class="input-group">
                    <label for="content">Содержание (максимум 2048 символов):</label>
                    <textarea id="content" name="content" maxlength="2048" required><?php echo htmlspecialchars($content); ?></textarea>
                    <span class="char-counter" id="counter-content">2048 символов осталось</span>
                </div>
                <button type="submit">Сохранить изменения</button>
            </form>
        </div>
    </section>
    <footer>
        <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
    </footer>
    <script>
        function updateCounter(fieldId, counterId, max) {
            const field = document.getElementById(fieldId);
            const counter = document.getElementById(counterId);
            counter.textContent = (max - [...field.value].length) + " символов осталось";
        }
        document.getElementById('title').addEventListener('input', function() {
            updateCounter('title', 'counter-title', 45);
        });
        document.getElementById('content').addEventListener('input', function() {
            updateCounter('content', 'counter-content', 2048);
        });
        updateCounter('title', 'counter-title', 45);
        updateCounter('content', 'counter-content', 2048);

        document.getElementById('edit-news-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new URLSearchParams(new FormData(form));
            formData.append('action', 'update');
            formData.append('news_id', <?php echo $news_id; ?>);
            fetch('ajax_news.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'news.php';
                } else {
                    document.getElementById('error-message').style.display = 'block';
                    document.getElementById('error-message').textContent = data.error || "Ошибка при сохранении изменений";
                }
            })
            .catch(error => {
                console.error('Ошибка:', error);
                document.getElementById('error-message').style.display = 'block';
                document.getElementById('error-message').textContent = "Произошла ошибка при сохранении изменений";
            });
        });
    </script>
</body>
</html>
