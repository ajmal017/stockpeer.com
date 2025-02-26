<?php 
  
namespace App\Console\Commands;

use DB;
use App;
use Auth;
use Crypt;
use Coinbase;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class RecordFuturesTrades extends Command 
{
	protected $name = 'stockpeer:recordfuturestrade';
	protected $description = 'Record a futures trade into a tradegroup.';

  //
  // Create a new command instance.
  // 
	public function __construct()
	{
		parent::__construct();
		
    // No DB logging.
    DB::connection()->disableQueryLog();
	}

  //
  // Execute the console command.
  //
	public function fire()
	{
    $this->info('Starting to record a futures trade.');
    
    Auth::loginUsingId(1);
    
    // Setup models.
    $symbols_model = App::make('App\Models\Symbols');
    $positions_model = App::make('App\Models\Positions');
    $tradegroups_model = App::make('App\Models\TradeGroups');
    
    // Get Tradegroup id.
    $TradeGroupId = $this->ask('Enter TradeGroupId or enter 0 for a new group. : ');

    // Get Trade.
    $open_price = $this->ask('What was the open price? : ');    
    $close_price = $this->ask('What was the close price? : ');   
    
    // Tradegroup time.
    if($TradeGroupId == 0)
    {
      $TradeGroupId = $tradegroups_model->insert([
        'TradeGroupsRisked' => 500, // what we risk per trade (margin)
        'TradeGroupsTitle' => 'Futures Day Trade',
        'TradeGroupsStatus' => 'Closed',
        'TradeGroupsStart' => date('Y-m-d H:i:s'),
        'TradeGroupsType' => 'Futures Day Trade',
        'TradeGroupsUpdatedAt' => date('Y-m-d H:i:s'),
        'TradeGroupsCreatedAt' => date('Y-m-d H:i:s')
      ]);
      
      $tradegroups_model->update([ 'TradeGroupsTitle' => 'Futures Day Trade #' . $TradeGroupId ], $TradeGroupId);
    }

    // Get symb id.
    if(! $syb_id = $symbols_model->get_symbol_id('/ES'))
    {
      $syb_id = $symbols_model->insert([ 'SymbolsShort' => '/ES', 'SymbolsFull' => 'E-mini S&P 500 Futures' ]);
    }

    // Record the new position.
    $positions_model->insert([
      'PositionsTradeGroupId' => $TradeGroupId,
      'PositionsAssetId' => 19,
      'PositionsSymbolId' => $syb_id,
      'PositionsType' => 'Future',
      'PositionsQty' => 0, // future....
      'PositionsOrgQty' => 50,
      'PositionsCostBasis' => ($open_price * 50),
      'PositionsAvgPrice' => $open_price, 
      'PositionsClosePrice' => ($close_price * 50),
      'PositionsDateAcquired' => date('Y-m-d H:i:s'),
      'PositionsStatus' => 'Closed',
      'PositionsClosed' => date('Y-m-d H:i:s'),
      'PositionsUpdatedAt' => date('Y-m-d H:i:s'), 
      'PositionsCreatedAt' => date('Y-m-d H:i:s')
    ]); 
    
    // Update Trade group.
    $tg = $tradegroups_model->get_by_id($TradeGroupId);
    
    $tradegroups_model->update([ 
      'TradeGroupsEnd' => date('Y-m-d H:i:s'),
      'TradeGroupsOpen' => $tg['TradeGroupsOpen'] + ($open_price * 50),
      'TradeGroupsClose' => $tg['TradeGroupsClose'] + ($close_price * 50),
      'TradeGroupsOpenCommission' => $tg['TradeGroupsOpenCommission'] + 2.01, 
      'TradeGroupsCloseCommission' => $tg['TradeGroupsCloseCommission'] + 2.01            
    ], $TradeGroupId);
    
    $this->info('Your trade is all recorded. TradeGroup Id #' . $TradeGroupId);
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
