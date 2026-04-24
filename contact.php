<?php
session_start();
require_once 'session_timeout.php';

if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time()-3600, '/');
    header("Location: contact.php");
    exit();
}

$home_link = 'index.php';
if (isset($_SESSION['role'])) {
    $home_link = ($_SESSION['role'] === 'admin') ? 'admin.php' : 'auth.php';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Контакты ЖКХ</title>
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
            position: relative;
        }
        header h1 {
            margin: 0;
            font-size: 2.5em;
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
        nav a:hover, nav a.active {
            background: #fff;
            color: #2193b0;
        }
        .logout-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: red;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
        }
        .logout-btn:hover {
            background: darkred;
        }
        .content {
            padding: 50px 20px;
            text-align: center;
            flex: 1;
        }
        .contact-info {
            max-width: 600px;
            margin: 0 auto;
            background: rgba(255,255,255,0.9);
            color: #000;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        iframe {
            width: 100%;
            height: 300px;
            border: none;
            margin-top: 20px;
            border-radius: 10px;
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
    <h1>Контакты ЖКХ</h1>
    <?php if (isset($_SESSION['user_id'])): ?>
        <form method="POST">
            <button type="submit" name="logout" class="logout-btn">Выйти</button>
        </form>
    <?php endif; ?>
    <nav>
        <a href="<?php echo $home_link; ?>" class="<?php echo (basename($_SERVER['PHP_SELF'])=='auth.php' || basename($_SERVER['PHP_SELF'])=='index.php' || basename($_SERVER['PHP_SELF'])=='admin.php') ? 'active' : ''; ?>">Главная</a>
        <a href="tariffs.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='tariffs.php' ? 'active' : ''; ?>">Тарифы</a>
        <a href="pokazaniya.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='pokazaniya.php' ? 'active' : ''; ?>">Показания</a>
        <a href="bills.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='bills.php' ? 'active' : ''; ?>">Начисления</a>
        <a href="payments.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='payments.php' ? 'active' : ''; ?>">Платежи</a>
<a href="contact.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='contact.php' ? 'active' : ''; ?>">Контакты</a>
        <a href="news.php" class="<?php echo basename($_SERVER['PHP_SELF'])=='news.php' ? 'active' : ''; ?>">Новости</a>
    </nav>
</header>
<section class="content">
    <div class="contact-info">
        <h2>Свяжитесь с нами</h2>
        <p><strong>Адрес:</strong> Москва, ул. Центральная, 10, ЖКХ Город</p>
        <p><strong>Телефон:</strong> +7 (495) 123-45-67</p>
        <p><strong>Email:</strong> info@jkh-gorod.ru</p>
        <p><strong>Часы работы:</strong> Пн-Пт: 9:00 - 18:00</p>
        <h3>Мы на карте:</h3>
        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d21418.206094477177!2d44.245859628000865!3d46.30526405488558!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x4101d17f46dff529%3A0x5721f92d425a153a!2z0J7QntCeICLQoNC10YHQv9GD0LHQu9C40LrQsNC90YHQutCw0Y8g0YPQv9GA0LDQstC70Y_RjtGJ0LDRjyDQutC-0LzQv9Cw0L3QuNGPIg!5e1!3m2!1sru!2snl!4v1738882517927!5m2!1sru!2snl" allowfullscreen="" loading="lazy"></iframe>
    </div>
</section>
<footer>
    <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>
