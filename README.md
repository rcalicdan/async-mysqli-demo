### How to Run the Demo

1. Clone the repo:
   ```bash
   git clone https://github.com/rcalicdan/Api-benchmark.git && cd Api-benchmark
   ```
2. **Update Credentials:** Open the `index.php` file and change the `DB_HOST`, `DB_USER`, and `DB_PASS` and `DB_PORT` constants to match your MySQL server. The user you provide will need permission to `CREATE` and `DROP` databases.
3. **Install Dependencies:** Run the following command in your project root to install the required dependencies:
   ```bash
   composer install
   ```
4. From your project root, start the PHP built-in web server:
   ```bash
   php -S localhost:8000 -t public
   ```
5. Open your web browser to **`http://localhost:8000`**.
6. Click the **"Run the Benchmark"** button.
7. You will see the benchmark results in your browser.

### What You Will See (The Dramatic Result)
