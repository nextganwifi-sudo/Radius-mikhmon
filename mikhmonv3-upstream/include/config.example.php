<?php 
/**
 * NextGan WiFi Solution (Based on Mikhmon v3.20) — Example Configuration File
 *
 * Setup Instructions:
 * 1. Copy this file to `include/config.php`:
 *      cp include/config.example.php include/config.php
 * 2. Default Administrator Login:
 *      Username: mikhmon
 *      Password: 1234
 * 3. After logging in at `/admin.php?id=login`, open Admin Settings to change
 *    the administrator username/password and configure RouterOS 6.x / 7.x sessions using the RouterOS API service.
 */
if(substr($_SERVER["REQUEST_URI"], -10) == "config.php"){header("Location:./");}; 
if(!isset($data) || !is_array($data)){$data = array();}
$data['mikhmon'] = array ('1'=>'mikhmon<|<mikhmon','mikhmon>|>aWNlbA==');
// Example RouterOS Session (replace with your MikroTik Router IP, API user, and encrypted password via Admin UI):
// $data['Router1'] = array ('1'=>'Router1!192.168.88.1','Router1@|@admin','Router1#|#aWNlbA==','Router1%NextGan-Hotspot','Router1^hotspot.example.net','Router1&Rp','Router1*10','Router1(1','Router1)','Router1=10','MikroTik-ROS7@!@enable');
