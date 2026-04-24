# Full-Stack-Web-Development
Full-stack web-project on theme: Housing and communal services

# Housing and Utilities Web Application

A PHP/MySQL web application prototype for managing housing and utility services.

The system supports user authorization, role-based access control, tariff viewing, meter reading submission, bill calculation, payment tracking, and news management. Management company employees can create, edit, and delete users, bills, payments, news, and meter data.

The project includes AJAX-based dynamic news updates, allowing changes made by an employee to appear for other users without manually refreshing the page.

The application also demonstrates database locking mechanisms for concurrent user operations. In particular, it compares an incorrect scenario without table locking, where duplicate bills can be created, with a correct scenario using `LOCK TABLES`, which prevents duplicate charge creation for the same meter or tariff within the same billing period.

Technologies used: PHP, MySQL, HTML, CSS, JavaScript, AJAX, Apache.
