### How to Run the Demo

1.  **Update Credentials:** Open the `index.php` file and change the `DB_HOST`, `DB_USER`, and `DB_PASS` and `DB_PORT` constants to match your MySQL server. The user you provide will need permission to `CREATE` and `DROP` databases.

2.  **Install Dependencies:** Run the following command in your project root to install the required dependencies:
    ```bash
    composer install
    ```
3.  From your project root, start the PHP built-in web server:
    ```bash
    php -S localhost:8000 -t public
    ```
4.  Open your web browser to **`http://localhost:8000`**.
5.  Click the **"Run the Benchmark"** button.

### What You Will See (The Dramatic Result)

