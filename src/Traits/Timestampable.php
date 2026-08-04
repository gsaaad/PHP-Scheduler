<?php

namespace App\Traits;

trait Timestampable {
    protected $createdAt;

    public function setCreatedAt($date) {
        $this->createdAt = $date;
    }

    public function getCreatedAt() {
        return $this->createdAt;
    }

    public function getFormattedTimestamp() {
        return date('Y-m-d H:i:s', strtotime($this->createdAt));
    }
}
