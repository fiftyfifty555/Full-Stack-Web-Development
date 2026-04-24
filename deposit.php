<?php
session_start();
require_once 'session_timeout.php';

// Если пользователь не авторизован, перенаправляем на страницу входа
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$conn = new mysqli('localhost', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $card_holder = trim($_POST['card_holder'] ?? '');
    $card_number_raw = trim($_POST['card_number'] ?? '');
    $card_number = preg_replace('/\D/', '', $card_number_raw);
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $cvv = trim($_POST['cvv'] ?? '');
    $amount = trim($_POST['amount'] ?? '');

    // Проверка владельца карты
    if (empty($card_holder)) {
        $errors[] = "Введите имя владельца карты.";
    } elseif (!preg_match('/^[a-zA-Zа-яА-Я\s]+$/u', $card_holder)) {
        $errors[] = "Имя владельца карты должно содержать только буквы и пробелы.";
    }

    // Проверка номера карты
    if (empty($card_number) || strlen($card_number) != 16) {
        $errors[] = "Введите корректный номер карты (ровно 16 цифр).";
    }

    // Проверка срока действия карты
    if (empty($expiry_date)) {
        $errors[] = "Введите срок действия карты.";
    } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry_date)) {
        $errors[] = "Срок действия карты должен быть в формате MM/YY.";
    } else {
        list($month, $year) = explode('/', $expiry_date);
        $month = (int)$month;
        $year = (int)$year + 2000;
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-01 00:00:00', $year, $month));
        if ($dt === false) {
            $errors[] = "Неверный формат даты.";
        } else {
            $dt->modify('last day of this month');
            $dt->setTime(23, 59, 59);
            $expiryTimestamp = $dt->getTimestamp();
            if ($expiryTimestamp < time()) {
                $errors[] = "Срок действия карты истек.";
            }
        }
    }

    // Проверка CVV
    if (empty($cvv) || !preg_match('/^\d{3}$/', $cvv)) {
        $errors[] = "Введите корректный CVV (ровно 3 цифры).";
    }

    // Проверка суммы
    if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
        $errors[] = "Введите сумму пополнения больше 0.";
    }

    if (empty($errors)) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("UPDATE users SET account_balance = account_balance + ? WHERE user_id = ?");
        $stmt->bind_param("di", $amount, $user_id);
        if ($stmt->execute()) {
            // Устанавливаем флаг успешного пополнения в сессии
            $_SESSION['payment_success'] = true;
            $_SESSION['payment_amount'] = $amount;
            // Перенаправляем на ту же страницу с помощью GET-запроса
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $errors[] = "Ошибка пополнения счёта. Попробуйте ещё раз.";
        }
        $stmt->close();
    }
}

// Проверяем, было ли успешное пополнение счета
if (isset($_SESSION['payment_success']) && $_SESSION['payment_success']) {
    $success = true;
    $amount = $_SESSION['payment_amount'];
    // Удаляем флаг успешного пополнения из сессии
    unset($_SESSION['payment_success']);
    unset($_SESSION['payment_amount']);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Пополнение счёта</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #6dd5ed, #2193b0);
            margin: 0;
            padding: 0;
            color: #333;
        }
        .container {
            max-width: 500px;
            margin: 80px auto;
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
        }
        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #2193b0;
            font-size: 2em;
        }
        form {
            display: flex;
            flex-direction: column;
        }
        label {
            margin: 12px 0 6px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="number"],
        input[type="password"] {
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1em;
        }
        input#expiry_date {
            letter-spacing: 2px;
        }
        .submit-btn {
            margin-top: 30px;
            padding: 14px;
            background: #2193b0;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background 0.3s;
        }
        .submit-btn:hover {
            background: #197a91;
        }
        .message {
            margin-top: 20px;
            text-align: center;
        }
        .error {
            color: red;
            font-size: 1em;
        }
        .success {
            color: green;
            font-size: 1em;
        }
        .back-link {
            display: block;
            margin-top: 30px;
            text-align: center;
            text-decoration: none;
            color: #2193b0;
            font-weight: bold;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var expiryInput = document.getElementById('expiry_date');
            if(expiryInput) {
                expiryInput.addEventListener('input', function(e) {
                    var value = expiryInput.value.replace(/\D/g, '');
                    if (value.length >= 2) {
                        expiryInput.value = value.substring(0, 2) + '/' + value.substring(2, 4);
                    } else {
                        expiryInput.value = value;
                    }
                });
            }
            var cardInput = document.getElementById('card_number');
            if(cardInput) {
                cardInput.addEventListener('input', function(e) {
                    var value = cardInput.value.replace(/\D/g, '');
                    value = value.substring(0, 16);
                    var parts = value.match(/.{1,4}/g);
                    if(parts) {
                        cardInput.value = parts.join('-');
                    } else {
                        cardInput.value = value;
                    }
                });
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <h1>Пополнение счёта</h1>
        <?php if (!empty($errors)): ?>
            <div class="message error">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="message success">
                <p>Счёт успешно пополнен на сумму <?php echo htmlspecialchars($amount); ?>!</p>
            </div>
            <a href="auth.php" class="back-link">Вернуться в личный кабинет</a>
        <?php else: ?>
            <form method="POST">
                <label for="card_holder">Владелец карты</label>
                <input type="text" id="card_holder" name="card_holder" required value="<?php echo htmlspecialchars($_POST['card_holder'] ?? ''); ?>">

                <label for="card_number">Номер карты (16 цифр, формат: XXXX-XXXX-XXXX-XXXX)</label>
                <input type="text" id="card_number" name="card_number" required placeholder="XXXX-XXXX-XXXX-XXXX" value="<?php echo htmlspecialchars($_POST['card_number'] ?? ''); ?>">

                <label for="expiry_date">Срок действия (MM/YY)</label>
                <input type="text" id="expiry_date" name="expiry_date" placeholder="MM/YY" required maxlength="5" value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>">

                <label for="cvv">CVV (ровно 3 цифры)</label>
                <input type="password" id="cvv" name="cvv" required maxlength="3" value="<?php echo htmlspecialchars($_POST['cvv'] ?? ''); ?>">

                <label for="amount">Сумма пополнения</label>
                <input type="number" id="amount" name="amount" step="0.01" required value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>">

                <button type="submit" class="submit-btn">Пополнить</button>
            </form>
            <a href="auth.php" class="back-link">Вернуться в личный кабинет</a>
        <?php endif; ?>
    </div>
</body>
</html>
