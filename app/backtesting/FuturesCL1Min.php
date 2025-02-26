<?php
	
namespace App\Backtesting;

use DB;
use App;
use Libraries\Rsi;

class FuturesCL1Min extends FuturesBase
{
  public $ups = 0;
  public $downs = 0; 
  
  //
  // Construct...
  //
  public function __construct()
  {
    parent::__construct();
  }
  
  //
  // Run....
  //
  public function run($parms)
  {
    $this->start_date = $parms['start_date'];
    $this->end_date = $parms['end_date'];
    $this->start_time = '09:00:00';
    $this->end_time = '13:00:00';
    
    // Set symbol we are backtesting
    $this->set_symbol('cl');
    
    // Set starting capital.
    $this->set_cash($parms['cash']);
    
    // Tick by 1 min.
    $this->run_1_min_trades();
  }

  //
  // On data. Every 1min candle stick.
  //
  public function on_data($quote)
  {  
    // get the current hour.
    $cur_hour = explode(':', $quote['Time'])[0];   
    
    // Get any orders we might have.
    $order = $this->get_first_position();
    
    // See if we have an order to close (long)
    if($order && ($order['qty'] > 0))
    {      
      // Hit profit target
      if($quote['Close'] >= ($order['open_price'] + 0.10)) 
      {          
        $this->ups = 0;
        $this->downs = 0;
        $this->order_close();    
      } else if($quote['Close'] <= ($order['open_price'] - 0.40)) // Hit stop loss
      {  
        $this->ups = 0;
        $this->downs = 0;
        $this->order_close(); 
      }
      
      // If we have an order and we did not close it we don't do anything else.
      return;
    }

    // See if we have an order to close (short)
    if($order && ($order['qty'] < 0))
    {      
      // Hit profit target
      if($quote['Close'] <= ($order['open_price'] - 0.10)) 
      {                  
        $this->ups = 0;
        $this->downs = 0;
        $this->order_close();    
      } else if($quote['Close'] >= ($order['open_price'] + 0.40)) // Hit stop loss
      {  
        $this->ups = 0;
        $this->downs = 0;
        $this->order_close(); 
      }
      
      // If we have an order and we did not close it we don't do anything else.
      return;
    }
    
    // --------- Start Strat --------- //
    
    // We only trade the 9:00 hour
    if($cur_hour != 9)
    {
      return;
    }

    $down = false;
    $up = false;
    $trigger_long = false;
    $trigger_short = false;
    
    if($quote['Open'] > $quote['Close'])
    {
      $this->downs++;
      $down = true;      
      
      if($this->ups >= 4)
      {
        $trigger_short = true;
      }      
      
      $this->ups = 0;
    } else
    {
      $this->ups++;
      $up = true;
      
      if($this->downs >= 4)
      {
        $trigger_long = true;
      } 
      
      $this->downs = 0;
    }

/*
    // Place a short order.
    if($trigger_short)
    {      
      $this->ups = 0;
      $this->downs = 0;
      
      // Place order.
      $this->order(-1);      
    }
*/
    
    // Place a long order.
    if($trigger_long)
    {      
      $this->ups = 0;
      $this->downs = 0;
      
/*
      // Get qty of order.
      $qty = floor($this->cash / 4000);
      
      if($qty == 0)
      {
        $qty = 1;
      } 
*/    
      
      // Place order.
      $this->order(1);
    }     
  }
  
  //
  // On Start of Day.
  //
  public function on_start_of_day($quote)
  {
    return true;
  }  
  
  //
  // On End of Day.
  //
  public function on_end_of_day($quote)
  {    
    // Close any still open orders.
    $this->order_close();
  }  
}

/* End File */