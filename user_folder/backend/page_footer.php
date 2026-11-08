<?php

$activePage = $activePage ?? "";

?>
    </main>
    <nav class="bottom-nav" aria-label="Main navigation">
        <a href="notifications.php" class="<?= $activePage === "notifications" ? "active-nav" : "" ?>">
            <i class="fa-solid fa-heart pink-nav"></i>
            <span>Notifications</span>
        </a>
        <a href="records.php" class="<?= $activePage === "records" ? "active-nav" : "" ?>">
            <i class="fa-solid fa-clipboard-list pink-nav"></i>
            <span>Vaccination<br>Records</span>
        </a>
        <a href="../index.php">
            <span class="active-nav-icon"><i class="fa-solid fa-house"></i></span>
            <span>Home</span>
        </a>
        <a href="appointments.php" class="<?= $activePage === "appointments" ? "active-nav" : "" ?>">
            <i class="fa-solid fa-circle-plus blue-nav"></i>
            <span>Appointment</span>
        </a>
        <a href="settings.php" class="<?= $activePage === "settings" ? "active-nav" : "" ?>">
            <i class="fa-solid fa-grip purple-nav"></i>
            <span>Settings</span>
        </a>
    </nav>
</div>
<script src="../app.js"></script>
</body>
</html>
