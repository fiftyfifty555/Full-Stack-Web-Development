<?php
session_start();
require_once 'session_timeout.php';

if (!isset($_SESSION['user_id'])) {
    // Если не авторизован
    // Можно либо редирект на index.php, либо разрешить читать новости гостям
    // Предположим, разрешаем гостевой доступ. Уберём редирект:
    // header("Location: index.php");
    // exit();
}

if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time()-3600, '/');
    header("Location: news.php");
    exit();
}

$conn = new mysqli('localhost', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

setlocale(LC_TIME, 'ru_RU.UTF-8');

$user_id   = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;
$home_link = ($user_role === 'admin') ? 'admin.php' : 'auth.php';

// Если роль пользователя "user" — получаем баланс
$account_balance = 0;
if ($user_role === 'user' && $user_id) {
    $stmt = $conn->prepare("SELECT account_balance FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($account_balance);
    $stmt->fetch();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Новости ЖКХ</title>
    <style>
        /* Универсальный стиль (как в auth.php, но с фиксированным меню) */
    body {
      font-family: Arial, sans-serif;
      background: linear-gradient(135deg, #6dd5ed, #2193b0);
      color: #fff;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }
    
    header {
      background: rgba(0,0,0,0.6);
      padding: 25px 120px 25px 140px; /* Добавляем отступы по бокам */
      position: fixed;
      top: 0; 
      left: 0;
      width: 100%;
      z-index: 1000;
      text-align: center;
      box-sizing: border-box; /* Важно для правильного расчета ширины */
      display: flex;
      flex-direction: column;
      align-items: center;
    }    
    header h1 {
      margin: 0;
      font-size: 2em;
    }
    
    header .balance {
      position: absolute;
      top: 20px;
      left: 20px;
      font-size: 1.5em;
      color: #fff;
      display: flex;
      align-items: center;
      gap: 15px;
    }
    
    header .balance a.deposit-btn {
      background: #4CAF50;
      color: #fff;
      text-decoration: none;
      padding: 10px 15px;
      border-radius: 5px;
      font-size: 1.1em;
      transition: background 0.3s;
      white-space: nowrap;
    }
    
    header .balance a.deposit-btn:hover {
      background: #45a049;
    }
    
    nav {
      display: flex;
      justify-content: center;
      gap: 30px;
      margin-top: 25px;
    }
    
    nav a {
      color: #fff;
      text-decoration: none;
      font-size: 1.3em;
      padding: 12px 30px;
      border: 2px solid #fff;
      border-radius: 25px;
      transition: 0.3s;
      white-space: nowrap;
    }
    
    nav a:hover, 
    nav a.active {
      background: #fff;
      color: #2193b0;
    }
    
    .logout-btn {
      position: absolute;
      top: 50%;
      right: 20px;
      transform: translateY(-50%);
      background: red;
      color: #fff;
      border: none;
      padding: 12px 20px;
      border-radius: 5px;
      cursor: pointer;
      font-size: 1em;
      max-width: 120px; 
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }    
    .logout-btn:hover {
      background: darkred;
    }
    
    .content {
      padding: 180px 20px 50px;
      text-align: center;
      flex: 1;
      max-width: 1400px;
      margin: 0 auto;
      width: 100%;
      box-sizing: border-box;
    }









        /* Стили для новостей */
        .news-item {
            background: rgba(255,255,255,0.9);
            color: #000;
            margin: 20px auto;
            padding: 20px;
            border-radius: 10px;
            width: 80%;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            position: relative;
            overflow-wrap: break-word;
            padding-bottom: 50px;
        }
        .news-item h3 {
            margin-top: 0;
            font-size: 1.5em;
        }
        .news-item p {
            font-size: 1.1em;
            line-height: 1.6;
            margin: 10px 0;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            text-align: left;
        }
        .news-item .full-content {
            display: none;
            font-size: 1.1em;
            line-height: 1.6;
            margin-top: 10px;
            overflow-wrap: break-word;
            text-align: left;
        }
        .news-date {
            position: absolute;
            top: 10px;
            left: 10px;
            font-size: 0.9em;
            color: #555;
        }
        .read-more {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: #2193b0;
            color: #fff;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            border: none;
            width: 150px;
            text-align: center;
        }
        .admin-actions {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 150px;
            display: flex;
            justify-content: space-between;
        }
        .admin-actions a {
            color: #fff;
            text-decoration: none;
            padding: 5px 0;
            border-radius: 5px;
            font-size: 0.9em;
            text-align: center;
            display: block;
            width: 48%;
        }
        .admin-actions a.edit {
            background: #2193b0;
        }
        .admin-actions a.delete {
            background: #ff4444;
        }

        footer {
            text-align: center;
            padding: 20px;
            background: rgba(0,0,0,0.8);
            color: #fff;
            margin-top: auto;
        }
        /* Форма создания новости */
        #create-news-form-container {
            margin-bottom: 20px;
            background: rgba(255,255,255,0.9);
            padding: 20px;
            border-radius: 10px;
            width: 80%;
            margin: 20px auto;
            color: #000;
            text-align: left;
        }
        #create-news-form-container h3 {
            margin-top: 0;
        }
        #create-news-form-container .input-group {
            margin-bottom: 10px;
        }
        #create-news-form-container label {
            display: block;
            margin-bottom: 5px;
        }
        #create-news-form-container input[type="text"],
        #create-news-form-container textarea {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }
        #create-news-form-container button {
            padding: 10px 20px;
            background: #2193b0;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
</head>
<body>
<header>
    <?php if (isset($_SESSION['user_id']) && $user_role === 'user'): ?>
      <div class="balance">
        Баланс: <?php echo htmlspecialchars($account_balance); ?>
        <a href="deposit.php" class="deposit-btn">Пополнить</a>
      </div>
    <?php endif; ?>
    <h1>Новости ЖКХ</h1>
    <?php if (isset($_SESSION['user_id'])): ?>
        <form method="POST" style="display:inline;">
            <button type="submit" name="logout" class="logout-btn">Выйти</button>
        </form>
    <?php endif; ?>
    <nav>
        <a href="<?php echo ($user_role === 'admin') ? 'admin.php' : 'auth.php'; ?>" 
           class="<?php echo (basename($_SERVER['PHP_SELF'])=='auth.php' || basename($_SERVER['PHP_SELF'])=='admin.php') ? 'active' : ''; ?>">
           Главная
        </a>
        <a href="tariffs.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='tariffs.php' ? 'active' : ''; ?>">
          Тарифы
        </a>
        <a href="pokazaniya.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='pokazaniya.php' ? 'active' : ''; ?>">
          Показания
        </a>
        <a href="bills.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='bills.php' ? 'active' : ''; ?>">
          Начисления
        </a>
        <a href="payments.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='payments.php' ? 'active' : ''; ?>">
          Платежи
        </a>
        <a href="contact.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='contact.php' ? 'active' : ''; ?>">
          Контакты
        </a>
        <a href="news.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='news.php' ? 'active' : ''; ?>">
          Новости
        </a>
    </nav>
</header>

<section class="content">
    <h2>Последние новости</h2>

    <?php if ($user_role === 'admin'): ?>
        <!-- Форма создания новости, видимая только администратору -->
        <div id="create-news-form-container">
            <h3>Создать новость</h3>
            <div id="create-news-error" style="color: red; display: none;"></div>
            <div id="create-news-success" style="color: green; display: none;"></div>
            <form id="create-news-form">
                <div class="input-group">
                    <label for="news-title">Заголовок (максимум 45 символов):</label>
                    <input type="text" id="news-title" name="title" maxlength="45" required>
                </div>
                <div class="input-group">
                    <label for="news-content">Содержание (максимум 2048 символов):</label>
                    <textarea id="news-content" name="content" maxlength="2048" required></textarea>
                </div>
                <button type="submit">Создать новость</button>
            </form>
        </div>
    <?php endif; ?>

    <div id="news-list">
        <?php
        $sql = "SELECT news_id, title, content, created_at FROM news ORDER BY created_at DESC";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $date = new DateTime($row['created_at']);
                $formatter = new IntlDateFormatter(
                    'ru_RU',
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::NONE,
                    null,
                    null,
                    "HH:mm d MMMM Y 'года'"
                );
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
                echo "<div class='news-item' id='news-item-{$row['news_id']}'>";
                echo "  <div class='news-date'>" . htmlspecialchars($formatted_date) . "</div>";
                echo "  <h3>" . htmlspecialchars($row['title']) . "</h3>";
                echo "  <p>" . htmlspecialchars($excerpt) . "</p>";
                echo "  <button class='read-more' onclick='toggleReadMore(this)'>Читать полностью</button>";
                echo "  <div class='full-content'>" . nl2br(htmlspecialchars($row['content'])) . "</div>";
                if ($user_role === 'admin') {
                    echo "  <div class='admin-actions'>";
                    echo "      <a href='edit_news.php?id={$row['news_id']}' class='edit'>Изменить</a>";
                    echo "      <a href='javascript:void(0);' onclick='deleteNews({$row['news_id']})' class='delete'>Удалить</a>";
                    echo "  </div>";
                }
                echo "</div>";
            }
        } else {
            echo "<p>Нет новостей</p>";
        }
        ?>
    </div>
</section>

<footer>
    <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>

<script>
    let expandedNews = [];

    function toggleReadMore(button) {
        const newsItem = button.parentElement;
        const newsId = newsItem.id.replace('news-item-', '');
        const preview = newsItem.querySelector('p');
        const fullContent = newsItem.querySelector('.full-content');

        if (fullContent.style.display === "" || fullContent.style.display === "none") {
            preview.style.display = "none";
            fullContent.style.display = "block";
            button.textContent = "Свернуть";
            expandedNews.push(newsId);
        } else {
            preview.style.display = "block";
            fullContent.style.display = "none";
            button.textContent = "Читать полностью";
            expandedNews = expandedNews.filter(id => id !== newsId);
        }
    }

    function nl2br(str) {
        return str.replace(/(?:\r\n|\r|\n)/g, '<br>');
    }

    function refreshNews() {
        fetch('ajax_news.php?action=get&t=' + Date.now())
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const newsList = document.getElementById('news-list');
                    const currentNewsIds = Array.from(newsList.children).map(item => item.id);

                    data.news.forEach(item => {
                        const existingItem = document.getElementById('news-item-' + item.news_id);
                        if (existingItem) {
                            // Обновляем заголовок, текст и полный контент
                            existingItem.querySelector('h3').textContent = item.title;
                            existingItem.querySelector('p').textContent = item.excerpt;
                            existingItem.querySelector('.full-content').innerHTML = nl2br(item.content);
                        } else {
                            // Если новости нет, добавляем в начало списка
                            let newsHTML = `
                              <div class="news-item" id="news-item-${item.news_id}">
                                <div class="news-date">${item.created_at}</div>
                                <h3>${item.title}</h3>
                                <p>${item.excerpt}</p>
                                <button class="read-more" onclick="toggleReadMore(this)">Читать полностью</button>
                                <div class="full-content">${nl2br(item.content)}</div>
                            `;
                            <?php if ($user_role === 'admin'): ?>
                            newsHTML += `
                                <div class="admin-actions">
                                  <a href="edit_news.php?id=${item.news_id}" class="edit">Изменить</a>
                                  <a href="javascript:void(0);" onclick="deleteNews(${item.news_id})" class="delete">Удалить</a>
                                </div>
                            `;
                            <?php endif; ?>
                            newsHTML += `</div>`;

                            newsList.insertAdjacentHTML('afterbegin', newsHTML);
                        }
                    });

                    // Удаляем те, которых нет в новых данных
                    currentNewsIds.forEach(id => {
                        if (!data.news.some(item => 'news-item-' + item.news_id === id)) {
                            const obsoleteItem = document.getElementById(id);
                            if (obsoleteItem) {
                                obsoleteItem.remove();
                            }
                        }
                    });

                    // Востанавливаем "expanded" для уже развёрнутых новостей
                    expandedNews.forEach(newsId => {
                        const ni = document.getElementById('news-item-' + newsId);
                        if (ni) {
                            const preview = ni.querySelector('p');
                            const fullContent = ni.querySelector('.full-content');
                            const readMoreButton = ni.querySelector('.read-more');
                            preview.style.display = 'none';
                            fullContent.style.display = 'block';
                            readMoreButton.textContent = 'Свернуть';
                        }
                    });
                }
            })
            .catch(error => console.error('Ошибка при обновлении новостей:', error));
    }

    setInterval(refreshNews, 2000);

    function deleteNews(newsId) {
        if (!confirm("Вы уверены, что хотите удалить новость?")) return;
        fetch('ajax_news.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete&news_id=' + newsId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const newsItem = document.getElementById('news-item-' + newsId);
                if (newsItem) newsItem.remove();
            } else {
                alert("Ошибка: " + (data.error || "Не удалось удалить новость."));
            }
        })
        .catch(error => {
            console.error('Ошибка:', error);
            alert("Произошла ошибка при удалении новости.");
        });
    }

    // Форма создания новости
    const createNewsForm = document.getElementById('create-news-form');
    if (createNewsForm) {
        createNewsForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const title = document.getElementById('news-title').value.trim();
            const content = document.getElementById('news-content').value.trim();
            const errorDiv = document.getElementById('create-news-error');
            const successDiv = document.getElementById('create-news-success');

            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';

            if (!title || !content) {
                errorDiv.textContent = "Поля не могут быть пустыми";
                errorDiv.style.display = 'block';
                return;
            }
            if (title.length > 45) {
                errorDiv.textContent = "Заголовок не должен превышать 45 символов";
                errorDiv.style.display = 'block';
                return;
            }
            if (content.length > 2048) {
                errorDiv.textContent = "Содержание не должно превышать 2048 символов";
                errorDiv.style.display = 'block';
                return;
            }

            const formData = new URLSearchParams(new FormData(createNewsForm));
            formData.append('action', 'create');

            fetch('ajax_news.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    successDiv.textContent = "Новость успешно создана!";
                    successDiv.style.display = 'block';
                    document.getElementById('news-title').value = "";
                    document.getElementById('news-content').value = "";
                    refreshNews();
                } else {
                    errorDiv.textContent = data.error || "Ошибка при создании новости";
                    errorDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Ошибка:', error);
                errorDiv.textContent = "Произошла ошибка при создании новости";
                errorDiv.style.display = 'block';
            });
        });
    }
</script>
</body>
</html>
