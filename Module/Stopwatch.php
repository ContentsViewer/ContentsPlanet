<?php

class Stopwatch{

    private $elapsedPrevious;

    private $startTime;
    private $isRunning;

    function  __construct(){

        $this->Reset();
    }

    public function Elapsed(){
        if($this->isRunning){
            return $this->elapsedPrevious + (microtime(true) - $this->startTime);
        }

        return $this->elapsedPrevious;
    }

    public function ElapsedString(){
        return sprintf("%.20f", $this->Elapsed());
    }

    
    public function Start(){
        if($this->isRunning){
            return;
        }
        

        $this->startTime = microtime(true);
        $this->isRunning = true;
    }

    public function Stop(){
        if(!$this->isRunning){
            return;
        }

        $this->elapsedPrevious += (microtime(true) - $this->startTime);
        $this->isRunning = false;
    }

    public function Restart(){
        $this->Stop();
        $this->Reset();
        $this->Start();
    }

    public function Reset(){
        $this->startTime = 0.0;
        $this->elapsedPrevious = 0.0;
        $this->isRunning = false;
    }
}



// $sw = new Stopwatch();

// $sw->Start();
// echo  $sw->ElapsedString() . "<br>";
// sleep(1);

// echo  $sw->ElapsedString() . "<br>";

// sleep(1);
// echo  $sw->ElapsedString() . "<br>";
// sleep(1);
// echo  $sw->ElapsedString() . "<br>";
// $sw->Restart();
// sleep(1);
// echo  $sw->ElapsedString() . "<br>";




?>