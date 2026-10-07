<?php
//Redirect user to dashboard based on role

function redirect_by_role(string $role): void
{
    switch ($role) {
        case 'Member':
            header("Location: ../member-dashboard.php");
            break;

        case 'Applicant':
            header("Location: ../applicant-dashboard.php");
            break;

        case 'job_seeker':
            header("Location: ../job-seeker-dashboard.php");
            break;

        default:
            header("Location: ../dashboard.php");
            break;
    }
    exit();
}

// Redirect logged-in users automatically
function redirect_if_logged_in(): void
{
    if (isset($_SESSION['user_id'], $_SESSION['role'])) {
        redirect_by_role($_SESSION['role']);
    }
}

//Require login before accessing a page
 
function require_login(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
        $_SESSION['login_error'] = 'Please login to continue.';
        header("Location: ../login.php");
        exit();
    }
}
