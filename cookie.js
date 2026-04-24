document.addEventListener('DOMContentLoaded', function() {
    const imageElement = document.getElementById('dynamic-image');
    const changeImageButton = document.getElementById('change-image-btn');
    const images = ['image1.jpg', 'image2.jpg', 'image3.jpg'];
    let currentImageIndex = getCookie('imageIndex') || 0;

    // Устанавливаем начальную картинку
    imageElement.src = images[currentImageIndex];

    changeImageButton.addEventListener('click', function() {
        currentImageIndex = (currentImageIndex + 1) % images.length;
        imageElement.src = images[currentImageIndex];
        setCookie('imageIndex', currentImageIndex, 7); // Сохраняем индекс на 7 дней
    });

    function setCookie(name, value, days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        const expires = "expires=" + date.toUTCString();
        document.cookie = name + "=" + value + ";" + expires + ";path=/";
    }

    function getCookie(name) {
        const cookieName = name + "=";
        const decodedCookie = decodeURIComponent(document.cookie);
        const cookieArray = decodedCookie.split(';');
        for (let i = 0; i < cookieArray.length; i++) {
            let cookie = cookieArray[i];
            while (cookie.charAt(0) === ' ') {
                cookie = cookie.substring(1);
            }
            if (cookie.indexOf(cookieName) === 0) {
                return cookie.substring(cookieName.length, cookie.length);
            }
        }
        return null;
    }

    // Логика для падающих листьев
    const leafContainer = document.getElementById('leaf-container');

    function createLeaf() {
        const leaf = document.createElement('div');
        leaf.classList.add('leaf');
        leaf.style.left = Math.random() * 100 + 'vw';
        leaf.style.animationDuration = Math.random() * 5 + 3 + 's';
        leafContainer.appendChild(leaf);

        leaf.addEventListener('click', () => {
            leaf.remove();
        });

        setTimeout(() => {
            leaf.remove();
        }, 10000); // Удаляем лист через 10 секунд
    }

    function startLeafFall() {
        setInterval(createLeaf, 1000); // Создаем новый лист каждую секунду
    }

    startLeafFall();
});
