// Клиентская валидация формы (дублируется на сервере)
function validateForm() {
  let loginInput = document.getElementById('login').value.trim();
  let passwordInput = document.getElementById('password').value.trim();

  if (!loginInput || !passwordInput) {
    alert("Поля логина и пароля не должны быть пустыми (client-side check).");
    return false;
  }
  return true;
}

// Показать/скрыть пароль
function togglePassword() {
  let pwd = document.getElementById('password');
  let eyeIcon = document.getElementById('eye-icon');
  if (pwd.type === 'password') {
    pwd.type = 'text';
    eyeIcon.textContent = "🙈";
  } else {
    pwd.type = 'password';
    eyeIcon.textContent = "👁️";
  }
}
