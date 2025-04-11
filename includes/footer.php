<?php
// Файл: includes/footer.php
// Общий подвал HTML страниц
?>
<!-- Конец основного контента страницы -->
</main>

<footer class="footer mt-auto py-3 bg-light">
  <div class="container text-center">
    <span class="text-muted">© <?php echo date('Y'); ?> <?php echo SITE_NAME; ?></span>
  </div>
</footer>

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<!-- ВАШ APP.JS -->
<script src="js/app.js"></script>

<!-- Контейнер для Toast сообщений -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
    <div id="toastPlacement" aria-live="polite" aria-atomic="true">
        <!-- Тосты будут появляться здесь -->
    </div>
</div>

</body>
</html>
<?php
// if (isset($db) && $db) { mysqli_close($db); } // Закрытие соединения лучше делать в скриптах по мере необходимости
?>