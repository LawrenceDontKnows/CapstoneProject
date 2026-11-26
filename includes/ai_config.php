<?php
// Google Gemini API key for Syntax AI Assistant.
// Set GEMINI_API_KEY in the environment (Render) or locally.
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-1.5-flash');
}