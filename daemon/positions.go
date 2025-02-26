package main

import (
  "time"
  "fmt"
  "os/exec"  
  "net/http"
  "crypto/md5"
  "encoding/hex"       
  "encoding/json" 
  "io/ioutil"   
)

type Positions struct {}

type PositionsSend struct {
  Type string `json:"type"`
  Timestamp string `json:"timestamp"`
  Data string `json:"data"`
}

//
// Get Positions from Tradier
//
func (t *Positions) DoPositions(UsersId int, UsersTradierAccountId string, channel_websocket chan string) {
  
  // Store the MD5 has of the last call.
  var md5_hash string
  
  // Get the tradier API key for this user.
  api_key, _ := t.get_tradier_api_key(UsersId) 
  
  // Loop through getting quotes over and over again.
  for {
    
    // No need to get quotes if the market is closed.
    open := is_market_open();
    if ! open {
      time.Sleep(2 * time.Second)
      continue
    }
          
    // Create URL request.
    url := fmt.Sprintf("https://api.tradier.com/v1/accounts/%s/positions", UsersTradierAccountId)
    
    // Setup http client
    client := &http.Client{}    
    
    // Setup api request
    req, _ := http.NewRequest("GET", url, nil)
    req.Header.Set("Accept", "application/json")
    req.Header.Set("Authorization", fmt.Sprint("Bearer ", api_key)) 

    res, err := client.Do(req)
        
    if err != nil {
      println("DoPositions: client.Do(req)")
      println(err)
      time.Sleep(time.Second * 3)
      continue     
    }        
    
    // Make sure the api responded with a 200
    if res.StatusCode != 200 {
      time.Sleep(time.Second * 3)
      continue
    }    
       
    // Read the data we got.
    body, err := ioutil.ReadAll(res.Body)
    
    if err != nil {
      println("DoPositions: ReadAll")
      println(err)
    }     
 
    // Get MD5 hash of the json we got.
    hash := t.get_md5_hash(string(body))
 
    // Test to see if there is new data or not. No sense in sending data 
    // down the websocket if the data has not changed.
    if hash == md5_hash {
      time.Sleep(time.Second * 3)
      continue
    } else {
      md5_hash = hash
    }
    
    // If we made it this far we need to record the orders in our db.
    // We let our friend the Laravel app take care of this.
    // TODO: This is not great as the php script checks every account (multi tenant)
    // We should rewrite the php script to support an argument of the UsersId
    orderCmd := exec.Command("php", "../artisan", "stockpeer:managepositions")
    err = orderCmd.Run()

    if err != nil {
      md5_hash = ""
      println("DoPositions: Exec")
      println(err)
      continue
    }

    // We only send a message to refresh positions. 
    // As positions are sort of complex we would rather just 
    // handle them in the laravel app. We are telling the web app
    // to simply make another api call to get updated data.
    // We do it this way because we don't want more than one app (laptop, ipad, desktop) 
    // All making Tradier api calls to see if there are new positions. This way we
    // poll Tradier in one place and then just update the positions on the web app
    // if things have changed.
    s := PositionsSend {
      Type: "Positions:refresh",
      Timestamp: time.Now().Format("01/02/06 3:04:05 pm"),
      Data: ""}
    
    b, err := json.Marshal(s)
    
    if err != nil {
      println("DoPositions: json.Marshal")
      println(err)
    } 
        
    // Send to the channel for websocket processing.  
    channel_websocket <- string(b)
    
    // Close body.
    res.Body.Close()
    
    // Sleep then do it again.
    time.Sleep(time.Second * 2)
  }  
}

//
// Get the tradier API key. If we ever make this a multi tenant app we need to make this smarter.
//
func (t *Positions) get_tradier_api_key(user_id int) (string, string) {
  
  // Loop through the users in the config to find the key
  for _, row := range Config.Users {
    if row.UsersId == user_id {
      return row.UsersTradierToken, ""
    }
  }
  
  // Return with error
  return "", "key not found"
}

//
// Return a string that is an MD5 Hash.
//
func (t *Positions) get_md5_hash(text string) string {
  hasher := md5.New()
  hasher.Write([]byte(text))
  return hex.EncodeToString(hasher.Sum(nil))
}

/* End File */