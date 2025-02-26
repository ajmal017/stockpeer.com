<?php 

namespace App\Console\Commands;

use DB;
use App;
use Auth;
use Crypt;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class PositionsManage extends Command 
{
	protected $name = 'stockpeer:managepositions';
	protected $description = 'Check to see if any of our positions need updating in our records.';
  private $pid_file = '/tmp/Stockpeer_PositionsManage.pid';

  //
  // Create a new command instance.
  // 
	public function __construct()
	{
		parent::__construct();
		
    // No DB logging.
    DB::connection()->disableQueryLog();
    
    // Log user in as spicer
    Auth::loginUsingId(1);
    
    // Setup Tradier
    $this->_tradier = App::make('App\Library\Tradier');
    $this->_tradier->set_token(Crypt::decrypt(Auth::user()->UsersTradierToken));  
	}

  //
  // Execute the console command.
  //
	public function fire()
	{
    $this->info('[' . date('n-j-Y g:i:s a') . '] Starting manage positions.');
    
    // Check lock file.
    if($this->_has_lock())
    {
      $this->info('[' . date('n-j-Y g:i:s a') . '] Ending manage positions. Already running else where.');
      return false;      
    } else
    {
      $this->_set_lock();
    }
    
    // Setup models    
    $orders_model = App::make('App\Models\Orders'); 
    $symbols_model = App::make('App\Models\Symbols');    
    $positions_model = App::make('App\Models\Positions');
    $tradegroups_model = App::make('App\Models\TradeGroups');    
    
    // Loop through the accounts of all users.
    $users = DB::table('Users')->where('UsersTradierToken', '!=', '')->get();
    
    foreach($users AS $user)
    {
      // Log user in.
      Auth::loginUsingId($user->UsersId);
      
      // Update our orders database first.
      $orders_model->log_orders_from_tradier();
      
      // Get positions
      if($data = $this->_tradier->get_account_positions(Auth::user()->UsersTradierAccountId, true))
      {	      
        // Log positions
        $this->_add_update_positions($data, $user);
        
        // See if we have any options expiring worthless today.
        $this->_close_expired_options($data); 
      
        // See if we have any positions that have closed.
        $this->_close_positions($data);      
      }
    }

    // Log.....
    $this->info('[' . date('n-j-Y g:i:s a') . '] Ending manage positions.');  
    
    // Remove lock
    $this->_remove_lock();
	}
	
	// ------------------- Private Helper Functions ------------ //
	
	//
	// Set pid lock
	//
	private function _set_lock()
	{
  	file_put_contents($this->pid_file, getmypid());
	}
	
	//
	// Remove lock
	//
	private function _remove_lock()
	{
    @unlink($this->pid_file);
	}
	
	//
	// See if we have a lock in place. A lock indicates another version of this app is running.
	//
	private function _has_lock()
	{
  	// See if we have a pid file
    if(! is_file($this->pid_file))
    {
      return false;
    }
    
    // Read the file.
    $running_pid = file_get_contents($this->pid_file);
  	
  	// Is the process still running
    if($this->_is_job_running($running_pid))
    {
      return true;
    }
  	
  	// If we made it this far we should just delete the pid file.
  	$this->_remove_lock();
  	
  	return false;
	}
	
	//
	// See if a process is running
  public function _is_job_running($pid)
  {
    try
    {
      $result = shell_exec(sprintf("ps %d", $pid));
      
      if(count(preg_split("/\n/", $result)) > 2)
      {
        return true;
      }
    } catch (Exception $e)
    {
    }

    return false;
  }	
	
	//
	// Close Positions - See if any positions are completely gone at Tradier.
	//
	private function _close_positions($positions)
	{
  	$db_ids = [];  	
  	$seen_orders = [];
  	$seen_tradegroups = [];  	
  	$broker_ids = [];
  	$orders_model = App::make('App\Models\Orders');
  	$positions_model = App::make('App\Models\Positions');
  	$tradegroups_model = App::make('App\Models\TradeGroups'); 
  	
  	// Get a list of positions that are currently open.
    foreach($positions AS $key => $row)
    {
      $broker_ids[] = (int) $row['id'];
    }
  	
    // Get positions that are curently open.
    $positions_model->set_col('PositionsStatus', 'Open');
    foreach($positions_model->get() AS $key => $row)
    {
      $db_ids[] = (int) $row['PositionsBrokerId'];
    }
    
    // Figure out what ids are not currently in the database.
    $diff_ids = array_diff($db_ids, $broker_ids);	  

    // So we assume the ids found in $diff_ids are positions that have closed at the broker and we need to update our database
    foreach($diff_ids AS $key => $row)
    {
      $positions_model->set_col('PositionsStatus', 'Open');
      $positions_model->set_col('PositionsBrokerId', $row);
      
      if(! $pos = $positions_model->first())
      {
        continue;
      }
      
      // Check the orders table for a closing orders.
      $orders_model->set_col('OrdersReviewed', 'No');
      $orders_model->set_col('OrdersStatus', 'Filled');
      $orders_model->db->where(function ($query) use ($pos) {
        $query->orWhere('OrdersSymbol', $pos['SymbolsShort']);
        $query->orWhere('OrdersLeg1OptionSymbol', $pos['SymbolsShort']);
        $query->orWhere('OrdersLeg2OptionSymbol', $pos['SymbolsShort']);
        $query->orWhere('OrdersLeg3OptionSymbol', $pos['SymbolsShort']);  
        $query->orWhere('OrdersLeg4OptionSymbol', $pos['SymbolsShort']);                                
      });     
      
      if(! $order = $orders_model->first())
      {
        continue;
      }
      
      // Mark seen
      $seen_orders[] = $order['OrdersId'];
      
      // Figure out the type of position we are dealing with here.
      $fs = [ 
              'OrdersSymbol' => [ 'OrdersFilledPrice', 'OrdersQty' ], 
              'OrdersLeg1OptionSymbol' => [ 'OrdersLeg1FilledPrice', 'OrdersLeg1Qty' ], 
              'OrdersLeg2OptionSymbol' => [ 'OrdersLeg2FilledPrice', 'OrdersLeg2Qty' ], 
              'OrdersLeg3OptionSymbol' => [ 'OrdersLeg3FilledPrice', 'OrdersLeg3Qty' ], 
              'OrdersLeg4OptionSymbol' => [ 'OrdersLeg4FilledPrice', 'OrdersLeg4Qty' ] 
            ];
            
      // Figure out which fill price we had
      foreach($fs AS $key2 => $row2)
      {
        if($order[$key2] == $pos['SymbolsShort'])
        {
          $fill_qty = $order[$row2[1]];
          $fill_price = $order[$row2[0]];
        }
      }
      
      // Finally close the positiion in the DB.
      if(abs($pos['PositionsQty']) == $fill_qty)
      {
        $close_qty = $pos['PositionsOrgQty'];
        
        if($pos['PositionsType'] != 'Stock')
        {
          $close_qty = $close_qty * 100;
        }

        $positions_model->update([
          'PositionsQty' => 0,
          'PositionsClosePrice' => ($fill_price * $close_qty),
          'PositionsStatus' => 'Closed',
          'PositionsClosed' => date('Y-m-d H:i:s') 
        ], $pos['PositionsId']);
        
        // Log trade groups we have seen.
        $seen_tradegroups[] = $pos['PositionsTradeGroupId'];      
      }  
    }
    
    // Update any tradegroups we touched
    foreach(array_unique($seen_tradegroups) AS $key => $row)
    {
      $stats = $tradegroups_model->get_stats($row);
      
      $tg = $tradegroups_model->get_by_id($row);
      
      $tradegroups_model->update([
        'TradeGroupsClose' => $stats['close_price'],
        'TradeGroupsCloseCommission' => $stats['open_comm'] + $tg['TradeGroupsCloseCommission'],
        'TradeGroupsEnd' => date('Y-m-d H:i:s'),
        'TradeGroupsStatus' => $stats['status']                   
      ], $row);  
    }    
    
    // Close out orders we have seen.
    foreach(array_unique($seen_orders) AS $key => $row)
    {
      $orders_model->update([ 'OrdersReviewed' => 'Yes' ], $row);
    }
    
    // Return happy.
    return true;
	}
	
  //
  // Add / Update positions
  //
  private function _add_update_positions($data, $user)
  {   
    $trade_group_id = null;
        
    // Loop through the positions and log them.
    foreach($data AS $key => $row)
    { 
      // See if we are updating or if this is a new position.
      if(! $this->update_position($row))
      {
        // Add the new position to the database.
        if($t = $this->add_new_position($row, $trade_group_id))
        {
          $trade_group_id = $t;
        }
      }
    }      
  }	
  
  //
  // Add a new postion to the database from the Tradier API.
  //
  public function add_new_position($row, $trade_group_id)
  {    
    $positions_model = App::make('App\Models\Positions');     
    $symbols_model = App::make('App\Models\Symbols');
    $assets_model = App::make('App\Models\Assets');
    $activity_model = App::make('App\Models\Activity'); 
    $tradegroups_model = App::make('App\Models\TradeGroups');    
    
    // Get the Tradier asset
    $assets_model->set_col('AssetsName', 'Tradier');
    if(! $asset = $assets_model->get())
    {
      return false;
    } else
    {
      $asset = $asset[0];
    }    
    
    // We do a quick check to make sure this position is not already closed.
    // This is common when we have closed something on our end but it is still 
    // open on the Tradier side. This is common with options we let expired 
    // because they do not close until the next day.
    $positions_model->set_col('PositionsStatus', 'Closed');
    $positions_model->set_col('PositionsBrokerId', $row['id']);
    $positions_model->set_col('PositionsDateAcquired', $row['date_acquired']);
    if($positions_model->get())    
    {
      return false;
    }
    
    // Setup a trade group. Since we are adding this trade we know this is part of a new trade group.
    // This is sort of buggy. It assumes we are checking for new positions all the time. 
    // If there is ever a gap in checking for new positions this could group a bunch of positions
    // into one group. Since we place orders from Stockpeer this should not be too much of an issue.
    if(is_null($trade_group_id))
    {      
      $trade_group_id = $tradegroups_model->insert([ 
        'TradeGroupsTitle' => 'New Trade', 
        'TradeGroupsStart' => date('Y-m-d H:i:s'),
        'TradeGroupsStatus' => 'Open'
      ]);
    }
    
    // Now we get the Symbols
    if(! $sym_id = $symbols_model->get_symbol_id($row['symbol']))
    {
      if($row['quote']['type'] == 'option')
      {
        $sym_id = $symbols_model->insert([
          'SymbolsShort' => strtoupper($row['symbol']),
          'SymbolsFull' => $row['quote']['description'],
          'SymbolsExpire' => date('Y-m-d', strtotime($row['quote']['expiration_date'])),  		
          'SymbolsUnderlying' => strtoupper($row['quote']['underlying']), 
          'SymbolsStrike' => $row['quote']['strike'],         		
          'SymbolsType' => 'Option',
          'SymbolsOptionType' => ucfirst(strtolower($row['quote']['option_type']))           
        ]);
      } else
      {
        $sym_id = $symbols_model->insert([
          'SymbolsShort' => strtoupper($row['symbol']),
          'SymbolsFull' => $row['quote']['description'],        		
          'SymbolsType' => 'Stock'           
        ]);          
      }
    } 
    
    // Must be a new position lets log it.
    $positions_model->insert([
      'PositionsAssetId' => $asset['AssetsId'],
      'PositionsTradeGroupId' => $trade_group_id,
      'PositionsBrokerId' => $row['id'],
      'PositionsSymbolId' => $sym_id,
      'PositionsType' => ($row['quote']['type'] == 'option') ? 'Option' : 'Stock',
      'PositionsQty' => $row['quantity'],
      'PositionsOrgQty' => $row['quantity'],
      'PositionsCostBasis' => $row['cost_basis'],
      'PositionsAvgPrice' => ($row['cost_basis'] / $row['quantity']),
      'PositionsStatus' => 'Open',
      'PositionsDateAcquired' => $row['date_acquired']
    ]);
    
    // Get the stats on the trade group so we can set the stats.
    if(! is_null($trade_group_id))
    {
      $stats = $tradegroups_model->get_stats($trade_group_id); 
      
      // Update tradegrop with Summary
      $tradegroups_model->update([
        'TradeGroupsTitle' => $stats['title'],
        'TradeGroupsOpen' => $stats['cost_base'],
        'TradeGroupsOpenCommission' => $stats['open_comm'],
        'TradeGroupsType' => $stats['type'],
        'TradeGroupsRisked' => $stats['risked']                     
      ], $trade_group_id);         
    }
    
    // Return the trade group id.
    return $trade_group_id;
  }  
  
  // 
  // Update positions if they have changed on the Tradier side.
  //
  public function update_position($row)
  {
    $updated = false;
    $positions_model = App::make('App\Models\Positions');
    $tradegroups_model = App::make('App\Models\TradeGroups');     
    
    // First we see if we have already logged this position. 
    $positions_model->set_col('PositionsStatus', 'Open');
    $positions_model->set_col('PositionsBrokerId', $row['id']);
    if($p = $positions_model->get())
    {
      // See if the PositionsDateAcquired has changed.
      if($p[0]['PositionsDateAcquired'] != $row['date_acquired'])
      {
        $updated = true;        
        $positions_model->update([ 'PositionsDateAcquired' => $row['date_acquired'] ], $p[0]['PositionsId']);
      }
      
      // See if the qty has changed.
      if($p[0]['PositionsQty'] != $row['quantity'])
      {
        $updated = true;        
        $positions_model->update([ 'PositionsQty' => $row['quantity'] ], $p[0]['PositionsId']);
      }
    
      // See if the PositionsCostBasis has changed.
      if(floatval($p[0]['PositionsCostBasis']) != floatval(round($row['cost_basis'], 2)))
      {    
        $updated = true;              
        $positions_model->update([ 'PositionsCostBasis' => $row['cost_basis'] ], $p[0]['PositionsId']);
      }     
      
      // If we updated the trade group run the stats update
      if($updated)
      {
        $stats = $tradegroups_model->get_stats($p[0]['PositionsTradeGroupId']); 
        
        // Update tradegrop with Summary
        $tradegroups_model->update([
          'TradeGroupsTitle' => $stats['title'],
          'TradeGroupsOpen' => $stats['cost_base'],
          'TradeGroupsOpenCommission' => $stats['open_comm'],
          'TradeGroupsType' => $stats['type'],
          'TradeGroupsRisked' => $stats['risked']                     
        ], $p[0]['PositionsTradeGroupId']); 
      }
    
      // Return true.
      return true;
    }
    
    // Nothing updated
    return false;    
  }  
	
	//
	// Close expired options
	//
	private function _close_expired_options($data)
	{   
    $positions_model = App::make('App\Models\Positions');  	
  	
  	foreach($data AS $key => $row)
  	{
      // First we check if we have this position in our records
      if(! $pos = $positions_model->get_open_by_symbol($row['symbol']))
      {
        continue;
      }    	
    	
      // (we only check this after the market closes)
      if(($pos['SymbolsType'] == 'Option') && 
          (strtotime($pos['SymbolsExpire'] . ' 13:05:00') <= strtotime('now')))
      {
        // Setup models      
        $positions_model = App::make('App\Models\Positions');
        $tradegroups_model = App::make('App\Models\TradeGroups');       
        
        // Make sure it expired worthless - Put
        if(($pos['SymbolsOptionType'] == 'Put') && ($row['quote']['bid'] <= 0.03))
        {
          // Close position.
          $positions_model->update([ 
            'PositionsStatus' => 'Closed',
            'PositionsClosed' => date('Y-m-d G:i:s'),
            'PositionsClosePrice' => 0,
            'PositionsQty' => 0,
            'PositionsNote' => 'Expired worthless.' 
          ], $pos['PositionsId']);
        
          // Close Trade Group
          $tradegroups_model->update([
            'TradeGroupsStatus' => 'Closed',
            'TradeGroupsEnd' => date('Y-m-d G:i:s'),
            'TradeGroupsNote' => 'Expired worthless.'
          ], $pos['PositionsTradeGroupId']);
        }
      }
    }      	
	}
	
  //
  // Get the console command arguments.
  //
	protected function getArguments()
	{
		return [];
	}

	//
	// Get the console command options.
	//
	protected function getOptions()
	{
		return [];
	}
}

/* End File */
