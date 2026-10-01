<?php
/** @var string|null $title */
use App\Core\Csrf;

$appName = (string) config('app.name');
$pageTitle = isset($title) && $title ? $title . ' · ' . $appName : $appName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0F172A">
    <meta name="color-scheme" content="light">
    <meta name="description" content="AI Interview Copilot: concise, CV-aware interview answer guidance.">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="app-base" content="<?= e(base_path()) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
