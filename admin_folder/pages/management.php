
<?php

require_once __DIR__ . "/../auth.php";
requireAdministrator();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Management | ImmuCare</title>

    <link rel="stylesheet" href="../style.css">

    <style>
        html,
        body {
            min-height: 100%;
            height: auto;
            overflow-y: auto;
        }

        .dashboard {
            min-height: 100vh;
            height: auto;
        }

        .main {
            min-height: 100vh;
            height: auto;
            min-width: 0;
            overflow: visible;
        }

        .management-container {
            padding: 25px;
        }

        /* Consistent upper-left Back button */
        .page-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            margin-bottom: 20px;
            background: #e8f8f3;
            color: #315f68;
            border-radius: 10px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s ease;
        }

        .page-back:hover {
            background: #d0eee4;
        }

        .page-back:focus-visible {
            outline: 3px solid #315f68;
            outline-offset: 3px;
        }

        .page-back svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .page-title {
            margin-bottom: 20px;
        }

        .page-title h1 {
            font-size: 26px;
            color: #315f68;
        }

        .management-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .management-card {
            background: white;
            border-radius: 16px;
            padding: 22px;
            box-shadow:
                0 4px 12px rgba(70, 110, 110, 0.08);
        }

        .management-card h2 {
            margin-bottom: 8px;
            color: #315f68;
        }

        .management-card p {
            color: #6a8589;
            margin-bottom: 18px;
            line-height: 1.5;
        }

        .feature-list {
            margin-bottom: 20px;
        }

        .feature-item {
            padding: 10px 12px;
            background: #f4fbf8;
            border-radius: 10px;
            margin-bottom: 8px;
            color: #55777d;
        }

        .management-btn {
            display: inline-block;
            text-decoration: none;
            background: #5ab49c;
            color: white;
            padding: 11px 18px;
            border-radius: 10px;
            font-weight: bold;
            transition: 0.2s ease;
        }

        .management-btn:hover {
            background: #459d86;
        }

        .management-btn:focus-visible {
            outline: 3px solid #315f68;
            outline-offset: 3px;
        }

        .appointment-card {
            background: linear-gradient(
                135deg,
                #fff0f5,
                #ffe8ef
            );
        }

        .vaccine-card {
            background: linear-gradient(
                135deg,
                #e9faf4,
                #e3f7f0
            );
        }

        @media (max-width: 800px) {
            .management-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .management-container {
                padding: 15px;
            }

            .page-back {
                margin-bottom: 18px;
            }
        }
    </style>

</head>

<body>

<div class="dashboard">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="logo">
            <img
                src="../images/logo.png"
                alt="ImmuCare"
                width="150"
            >
        </div>

        <nav>

            <a href="../index.php">
                Dashboard
            </a>

            <a href="records.php">
                Records
            </a>

            <a href="guardian_accounts.php">
                Guardian Accounts
            </a>

            <a
                href="management.php"
                class="active"
            >
                Management
            </a>

            <a href="monitoring.php">
                Monitoring
            </a>

            <a href="announcements.php">
                Announcements
            </a>

            <a href="reports.php">
                Reports
            </a>

            <a href="settings.php">
                Settings
            </a>

        </nav>

    </aside>

    <!-- MAIN -->
    <main class="main">

        <!-- TOP NAVIGATION BAR -->
        <header class="topbar">

            <div class="welcome-admin">
                Management
            </div>

            <div class="profile">
                🔔
                👤 Profile
            </div>

        </header>

        <!-- MANAGEMENT CONTENT -->
        <section class="management-container">

            <!-- Upper-left Back Button -->
            <a
                href="../index.php"
                class="page-back"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                Back to Dashboard
            </a>

            <div class="page-title">
                <h1>Management</h1>
            </div>

            <div class="management-grid">

                <!-- VACCINATION MANAGEMENT -->
                <div class="management-card vaccine-card">

                    <h2>Vaccination</h2>

                    <p>
                        Manage vaccine information and inventory.
                    </p>

                    <div class="feature-list">

                        <div class="feature-item">
                            View vaccine name
                        </div>

                        <div class="feature-item">
                            View doses
                        </div>

                        <div class="feature-item">
                            View batch number
                        </div>

                        <div class="feature-item">
                            View expiration date
                        </div>

                        <div class="feature-item">
                            Inventory status
                        </div>

                    </div>

                    <a
                        href="vaccines.php"
                        class="management-btn"
                    >
                        Open Vaccination Management
                    </a>

                </div>

                <!-- APPOINTMENT MANAGEMENT -->
                <div
                    class="management-card appointment-card"
                    id="appointments"
                >

                    <h2>Appointments</h2>

                    <p>
                        View and manage upcoming appointment requests.
                    </p>

                    <div class="feature-list">

                        <div class="feature-item">
                            Upcoming appointments
                        </div>

                        <div class="feature-item">
                            Accept appointment
                        </div>

                        <div class="feature-item">
                            Reject appointment
                        </div>

                        <div class="feature-item">
                            Reschedule appointment
                        </div>

                        <div class="feature-item">
                            Appointment history
                        </div>

                    </div>

                    <a
                        href="appointments.php"
                        class="management-btn"
                    >
                        Open Appointment Management
                    </a>

                </div>

                <!-- GUARDIAN FEEDBACK -->
                <div class="management-card">

                    <h2>Guardian Feedback</h2>

                    <p>
                        Review questions, suggestions, and problem
                        reports sent from the user Help and
                        Support settings.
                    </p>

                    <div class="feature-list">

                        <div class="feature-item">
                            View feedback from guardians
                        </div>

                        <div class="feature-item">
                            Track open and resolved items
                        </div>

                    </div>

                    <a
                        href="feedback.php"
                        class="management-btn"
                    >
                        Open Feedback Inbox
                    </a>

                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
