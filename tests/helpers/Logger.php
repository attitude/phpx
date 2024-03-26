<?php declare(strict_types = 1);

namespace Attitude\PHPX;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class Logger implements LoggerInterface {
  public function emergency($message, array $context = array()): void {
    $this->log(LogLevel::EMERGENCY, $message, $context);
  }

  public function alert($message, array $context = array()): void {
    $this->log(LogLevel::ALERT, $message, $context);
  }

  public function critical($message, array $context = array()): void {
    $this->log(LogLevel::CRITICAL, $message, $context);
  }

  public function error($message, array $context = array()): void {
    $this->log(LogLevel::ERROR, $message, $context);
  }

  public function warning($message, array $context = array()): void {
    $this->log(LogLevel::WARNING, $message, $context);
  }

  public function notice($message, array $context = array()): void {
    $this->log(LogLevel::NOTICE, $message, $context);
  }

  public function info($message, array $context = array()): void {
    $this->log(LogLevel::INFO, $message, $context);
  }

  public function debug($message, array $context = array()): void {
    $this->log(LogLevel::DEBUG, $message, $context);
  }

  public function log($level, $message, array $context = array()): void {
    $keys = array_filter(array_keys($context), 'is_string');
    $length = match(count($keys) > 0) {
      true => max(array_map('strlen', $keys)),
      false => 0,
    };

    $contextFormatted = '';

    foreach ($context as $key => $value) {
      if (is_int($key)) {
        $contextFormatted .= json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
      } else {
        $contextFormatted .= "\033[90m".str_pad("{$key}:", $length + 2)."\033[0m".json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
      }
    }

    $opening = match($level) {
      LogLevel::EMERGENCY => "\033[41;37m",
      LogLevel::ALERT => "\033[41;37m",
      LogLevel::CRITICAL => "\033[41;37m",
      LogLevel::ERROR => "\033[31m",
      LogLevel::WARNING => "\033[33m",
      LogLevel::NOTICE => "\033[36m",
      LogLevel::INFO => "\033[32m",
      LogLevel::DEBUG => "\033[34m",
      default => "\033[39m",
    };

    $closing = "\033[0m";

    echo sprintf("{$opening}[%s]{$closing} \033[1m%s\033[0m\n%s\n", strtoupper($level), $message, $contextFormatted);
  }
}
