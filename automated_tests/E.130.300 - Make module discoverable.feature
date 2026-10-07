Feature: E.130.300 - The system shall support the ability to make Project XML Sync external module discoverable by users.

  As a REDCap end user
  I want to see that Project XML Sync is functioning as expected

Scenario: E.130.300 - Make module discoverable by users
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    When I click on the link labeled "Control Center"
    # EMAIL ADDRESS SET FOR REDCAP ADMIN - without it, emails are not send out from system
    When I click on the link labeled "General Configuration"
    Then I should see "General Configuration"
    When I enter "redcap@test.instance" into the input field labeled "Email Address of REDCap Administrator"
    And I click on the button labeled "Save Changes"
    Then I should see "Your system configuration values have now been changed"

    When I click on the link labeled "Manage"
    Then I should see "External Modules - Module Manager"
    And I should NOT see "Project XML Sync - v1.0.0"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    Then I should see "Available Modules"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Project XML Sync - v1.0.0"
    And I should NOT see "Discoverable"
    
    When I click on the button labeled "Configure"
    And I check the checkbox labeled "Make module discoverable by users"
    And I click on the button labeled "Save"
    Then I should see "Project XML Sync - v1.0.0"
    And I should see "Discoverable"
    And I logout
    
    Given I login to REDCap with the user "Test_User1"
    When I create a new project named "E.130.300" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/Project_redcap_val_nodata.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Project Module Manager"
    And I should NOT see "Project XML Sync - v1.0.0"

    When I click on the button labeled "View available modules"
    Then I should see "Project XML Sync - v1.0.0"
    And I should see "Discoverable"
    And I should NOT see "Activation request is pending"
    When I click on the button labeled "Request Activation" in the row labeled "Project XML Sync"
    # Then I should see "SUCCESS"
    # And I click on the button labeled "Close"
    And I should NOT see "Project XML Sync - v1.0.0"

    When I click on the button labeled "View available modules"
    Then I should see "Project XML Sync - v1.0.0"
    And I should see "Discoverable"
    And I should see "Activation request is pending"
    And I logout

    # # Commenting this as HTML is not loading into iframe
    # # Hence this is done manually
    # Given I login to REDCap with the user "Test_Admin"
    # When I click on the link labeled "Control Center"
    # Then I should see a link labeled "To-Do List"

    # Given I click on the link labeled "To-Do List"
    # Then I should see "Pending Requests"
    # And I should see the "Activate external module" request created for the project named "E.130.300" within the Pending Requests table
    # When I click on the process request icon for the "Activate external module" request created for the project named "E.130.300" within the Pending Requests table
    # # HTML is not loading into iFrame
    # Then I should see "Enable module 'Project XML Sync - '?" in the iframe
    # And I click on the button labeled "Enable" in the iframe
    # And I close the iframe window
    # Then I should see the "Activate external module" request created for the project named "E.130.300" within the Completed & Archived Requests table
    # And I logout

    # Given I login to REDCap with the user "Test_User1"
    # When I click on the link labeled "My Projects"
    # And I click on the link labeled "E.130.300"
    # And I click on the link labeled "Manage"
    # Then I should see "External Modules - Project Module Manager"
    # And I should see "Project XML Sync - v1.0.0"
    # And I should see "Discoverable"
    # And I logout

    # Disable external module in Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    And I click on the button labeled "Disable"
    Then I should see "Disable module?"
    When I click on the button labeled "Disable module"
    Then I should NOT see "Project XML Sync - v1.0.0"
    And I logout

    # Verify no exceptions are thrown in the system
    Given I open Email
    Then I should NOT see an email with subject "REDCap External Module Hook Exception - project_xml_sync"